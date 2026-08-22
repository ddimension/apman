<?php

namespace ApManBundle\Controller;

use ApManBundle\Factory\CacheFactory;
use ApManBundle\Factory\MqttFactory;
use ApManBundle\Service\AccessPointService;
use ApManBundle\Service\wrtJsonRpc;
use Psr\Log\LoggerInterface;
use Sonata\AdminBundle\Controller\CRUDController;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CustomActionsController extends CRUDController
{
    private $rpcService;
    private $logger;
    private $mqttFactory;
    private $cacheFactory;
    private $apService;

    /**
     * Everything this controller needs comes in through the constructor.
     *
     * $this->container of a Sonata CRUD controller is the service subscriber
     * locator of AbstractController — it holds twig, the router and a handful
     * of Sonata services, and nothing else. Asking it for an application
     * service throws, which is what every batch action here used to do the
     * moment the confirmation was acknowledged.
     */
    public function __construct(
        wrtJsonRpc $rpcService,
        LoggerInterface $logger,
        MqttFactory $mqttFactory,
        CacheFactory $cacheFactory,
        AccessPointService $apService,
    ) {
        $this->rpcService = $rpcService;
        $this->logger = $logger;
        $this->mqttFactory = $mqttFactory;
        $this->cacheFactory = $cacheFactory;
        $this->apService = $apService;
    }

    /**
     * Ask the clients of these access points to go somewhere else, and give
     * them the time to do it.
     *
     * An 802.11v BSS transition request with disassociation imminent set, and
     * the bsses of the same ssid on the other access points as candidates: a
     * client that understands it roams on its own instead of being dropped
     * when the bss goes down underneath it.
     *
     * hostapd's ubus object used to have wnm_disassoc_imminent for this and no
     * longer does — it answered "method not found" on every access point here,
     * so nothing was ever evacuated. bss_transition_request is what the client
     * detail page uses and what the access points actually implement.
     *
     * @param iterable<\ApManBundle\Entity\AccessPoint> $aps
     */
    private function evacuateClients(iterable $aps, int $deadline = 2): void
    {
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            return;
        }
        $haveClients = false;
        foreach ($aps as $ap) {
            foreach ($ap->getRadios() as $radio) {
                foreach ($radio->getDevices() as $device) {
                    $status = $this->cacheFactory->getCacheItemValue('status.device.'.$device->getId());
                    if (!is_array($status) || !isset($status['assoclist']['results']) || !is_array($status['assoclist']['results'])) {
                        continue;
                    }
                    foreach ($status['assoclist']['results'] as $c) {
                        if (!is_array($c) || !count($c) || !isset($c['mac'])) {
                            continue;
                        }
                        $haveClients = true;
                        $opts = new \stdClass();
                        $opts->addr = $c['mac'];
                        $opts->abridged = true;
                        $opts->disassociation_imminent = true;
                        // in beacon intervals, roughly 100 ms each
                        $opts->disassociation_timer = $deadline * 10;
                        $opts->neighbors = [];
                        foreach ($device->getSsid()->getDevices() as $neighbor) {
                            if ($neighbor->getRadio()->getAccessPoint() == $ap) {
                                continue;
                            }
                            $rrm = json_decode(json_encode($neighbor->getRrm()));
                            if (is_object($rrm) && property_exists($rrm, 'value') && is_array($rrm->value) && isset($rrm->value[2])) {
                                $opts->neighbors[] = $rrm->value[2];
                            }
                        }
                        $topic = 'apman/ap/'.$ap->getName().'/command';
                        // One of these per associated client, all to the same
                        // access point, each running until the station answers
                        // or the disassociation timer runs out. Sent
                        // synchronously they add up to the whole evacuation
                        // spent with the agent unable to do anything else.
                        $cmd = $this->rpcService->createRpcRequest('evacuate-'.$device->getIfname(), $this->rpcService->asyncMethod($ap), null, 'hostapd.'.$device->getIfname(), 'bss_transition_request', $opts);
                        $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
                        $client->publish($topic, json_encode($cmd));
                    }
                }
            }
        }
        if ($haveClients) {
            sleep($deadline);
        }
    }

    /**
     * Turn one applyConfig() report into a flash message.
     *
     * @return bool whether the run was a success
     */
    private function reportProvisioning(\ApManBundle\Entity\AccessPoint $ap, array $report): bool
    {
        if ($report['ok'] ?? false) {
            $note = $report['note'] ?? (($report['change_count'] ?? 0).' change(s) applied');
            $this->addFlash('sonata_flash_success', $ap->getName().': '.$note);

            return true;
        }

        $message = $report['error'] ?? 'provisioning failed';
        if (!empty($report['failed'])) {
            $failed = [];
            foreach (array_slice($report['failed'], 0, 3, true) as $id => $why) {
                $failed[] = $id.': '.$why;
            }
            $message .= ' — '.implode('; ', $failed);
        }
        $this->logger->error($ap->getName().': provisioning failed: '.$message);
        $this->addFlash('sonata_flash_error', $ap->getName().': '.$message);

        return false;
    }

    /**
     * Provision the selected access points the same way the ap detail page
     * does: stage the whole wireless configuration in one uci transaction, ask
     * the access point for the resulting diff and only apply it with rpcd's
     * rollback timer armed.
     *
     * The old code called publishConfig() directly, which stages the
     * transaction and stops there — with a ubus session known it never
     * committed, so a run that reported "Reconfigured." had changed nothing on
     * the access point.
     */
    public function batchActionConfigure(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
        $selectedModels = iterator_to_array($selectedModelQuery->execute(), false);
        $this->evacuateClients($selectedModels);

        foreach ($selectedModels as $ap) {
            $this->reportProvisioning($ap, $this->apService->applyConfig($ap));
        }

        return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
    }

    /**
     * Stop the radios, provision, reboot.
     *
     * The reboot happens whether or not the configuration went through: this
     * is the action people reach for when an access point is in a bad state,
     * and the flash messages say what the configuration run did.
     */
    public function batchActionConfigureAndRestart(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
        $selectedModels = iterator_to_array($selectedModelQuery->execute(), false);
        $this->evacuateClients($selectedModels);

        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->addFlash('sonata_flash_error', 'Cannot connect to mqtt.');

            return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
        }

        foreach ($selectedModels as $ap) {
            $this->apService->stopRadio($ap);
            $this->reportProvisioning($ap, $this->apService->applyConfig($ap));

            $topic = 'apman/ap/'.$ap->getName().'/command';
            $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'system', 'reboot', new \stdClass());
            $client->publish($topic, json_encode($cmd));
            $this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
        }

        $this->addFlash('sonata_flash_success', 'Reboot initiated.');

        return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
    }

    public function batchActionStopRadio(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
        $selectedModels = iterator_to_array($selectedModelQuery->execute(), false);
        $this->evacuateClients($selectedModels);

        foreach ($selectedModels as $ap) {
            $this->apService->stopRadio($ap);
        }
        $this->addFlash('sonata_flash_success', 'Stopped radios.');

        return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
    }

    public function batchActionStartRadio(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
        foreach ($selectedModelQuery->execute() as $ap) {
            $this->apService->startRadio($ap);
        }
        $this->addFlash('sonata_flash_success', 'Started radios.');

        return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
    }

    public function batchActionWiFiRestart(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
        foreach ($selectedModelQuery->execute() as $ap) {
            $session = $this->rpcService->getSession($ap);
            if (false === $session) {
                $this->addFlash('sonata_flash_error', 'Cannot connect to AP '.$ap->getName());

                return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
            }
            $opts = new \stdClass();
            $opts->command = 'wifi';
            $opts->params = ['reload'];
            $session->call('file', 'exec', $opts);
        }
        $this->addFlash('sonata_flash_success', 'Called "wifi restart".');

        return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
    }

    public function batchActionReboot(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
        $client = $this->mqttFactory->getClient();
        if (!$client) {
            $this->logger->error('Failed to get mqtt client.');
            $this->addFlash('sonata_flash_error', 'Cannot connect to mqtt.');

            return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
        }

        foreach ($selectedModelQuery->execute() as $ap) {
            $topic = 'apman/ap/'.$ap->getName().'/command';
            $cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'system', 'reboot', new \stdClass());
            $this->logger->info($ap->getName().': Sent reboot command.');
            $client->publish($topic, json_encode($cmd));
        }
        $this->addFlash('sonata_flash_success', 'Reboot initiated.');
        $client->disconnect();

        return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
    }

    public function batchActionRefreshRadios(ProxyQueryInterface $selectedModelQuery, Request $request)
    {
        foreach ($selectedModelQuery->execute() as $ap) {
            $this->apService->refreshRadios($ap);
        }
        $this->addFlash('sonata_flash_success', 'Refreshed initiated.');

        return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
    }


    /**
     * @param $id
     */
    public function syslogAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
        $ap = $object;
        header('Content-Type: text/plain');
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            $this->addFlash('sonata_flash_error', 'Failed to get session.');

            return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
        }
        $opts = new \stdClass();
        $opts->command = 'logread';
        $opts->params = ['-l', '1000'];
        $stat = $session->call('file', 'exec', $opts);
        if (isset($stat->stdout)) {
            echo $stat->stdout;
        }
        exit();
        //return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * @param $id
     */
    public function loginAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
        $ap = $object;
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            $this->addFlash('sonata_flash_error', 'Failed to get session.');

            return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
        }
        $opts = new \stdClass();
        $opts->values = new \stdClass();
        $opts->values->user = 'root';
        $opts->values->token = substr(hash('sha256', uniqid().microtime()), 0, 32);
        $opts->values->section = substr(hash('sha256', uniqid().microtime()), 0, 32);
        $stat = $session->call('session', 'set', $opts);
        $url = $ap->getUbusUrl();
        $url = str_replace('/ubus', '/cgi-bin/luci/?sysauth='.$session->getSessionId(), $url);

        return new RedirectResponse($url);
    }

    /**
     * @param $id
     */
    public function lldpAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
        $ap = $object;
        header('Content-Type: text/plain');
        $session = $this->rpcService->getSession($ap);
        if (false === $session) {
            $this->addFlash('sonata_flash_error', 'Failed to get session.');

            return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
        }
        $opts = new \stdClass();
        $opts->command = 'lldpcli';
        $opts->params = ['show', 'neighbors'];
        $stat = $session->call('file', 'exec', $opts);
        if (isset($stat->stdout)) {
            echo $stat->stdout;
        }
        exit();
        //return new RedirectResponse($this->admin->generateUrl('list'));
    }

    /**
     * @param $id
     */
    public function radioStatusAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
        header('Content-Type: text/plain');
        $session = $this->rpcService->getSession($object->getAccessPoint());
        if (false === $session) {
            $this->addFlash('sonata_flash_error', 'Failed to get session.');

            return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
        }
        $opts = new \stdClass();
        $opts->device = $object->getName();
        $stat = $session->call('iwinfo', 'info', $opts);
        print_r($stat);

        echo "\n";
        echo "#########################\n";
        echo '# Phy Info '.$stat->phy."\n";
        echo "#########################\n";
        echo "\n";
        $opts = new \stdClass();
        $opts->command = 'iw';
        $opts->params = ['phy', $stat->phy, 'info'];
        $stat = $session->call('file', 'exec', $opts);
        if (isset($stat->stdout)) {
            echo $stat->stdout;
        }
        exit();
    }

    /**
     * @param $id
     */
    public function radioNeighborsAction($id)
    {
        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
        }
        header('Content-Type: text/plain');
        $session = $this->rpcService->getSession($object->getAccessPoint());
        if (false === $session) {
            $this->addFlash('sonata_flash_error', 'Failed to get session.');

            return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
        }
        $opts = new \stdClass();
        $opts->device = $object->getName();
        $stat = $session->call('iwinfo', 'scan', $opts);
        if (!isset($stat->results)) {
            $this->addFlash('sonata_flash_error', 'Failed to scan.: '.print_r($stat, true));

            return new RedirectResponse($this->admin->generateUrl('list', ['filter' => $this->admin->getFilterParameters()]));
        }

        return $this->render('default/neighbors.html.twig', ['neighbors' => $stat->results]);
    }
}
