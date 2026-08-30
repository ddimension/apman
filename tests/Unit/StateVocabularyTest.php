<?php

namespace ApManBundle\Tests\Unit;

use ApManBundle\Library\AccessPointState;
use ApManBundle\Library\NodeState;
use ApManBundle\Service\StateTreeService;
use PHPUnit\Framework\TestCase;

/**
 * The mapping that decides whether the flat machine can be deleted.
 *
 * Two vocabularies say the same things in different words. The record on /aps
 * counts pairings as agreeing or not, and it is only worth reading if this
 * mapping is right — a wrong entry would either hide a real disagreement or
 * invent one, and both would be read as an answer to "can the old machine go".
 *
 * The tree knows two states the flat machine has no word for, DEGRADED and
 * UNKNOWN. Those are a difference, not a disagreement: there is nothing on the
 * other side to disagree with. They must not map to anything.
 */
class StateVocabularyTest extends TestCase
{
    public function testEveryFlatStateHasATreeStateThatMeansIt(): void
    {
        $pairs = [
            'OFFLINE' => 'STATE_OFFLINE',
            'ONLINE' => 'STATE_ONLINE',
            'CONFIGURING' => 'STATE_PENDING',
            'FAILED' => 'STATE_FAILED',
            'CAC' => 'STATE_DFS_RUNNING',
            'READY' => 'STATE_DFS_READY',
            'ACTIVE' => 'STATE_ACTIVE',
        ];
        foreach ($pairs as $tree => $flat) {
            $this->assertTrue(StateTreeService::agrees($tree, $flat),
                $tree.' and '.$flat.' are the same thing and must be counted as agreeing');
        }
    }

    public function testTheStatesTheFlatMachineHasNoWordForMapToNothing(): void
    {
        foreach (['DEGRADED', 'UNKNOWN'] as $tree) {
            foreach (['STATE_ACTIVE', 'STATE_OFFLINE', 'STATE_ONLINE', 'STATE_FAILED'] as $flat) {
                $this->assertFalse(StateTreeService::agrees($tree, $flat),
                    $tree.' has no counterpart, so nothing may be called equal to it');
            }
        }
    }

    public function testTheTwoActivationStepsAreNotConfused(): void
    {
        // READY is "the radios are up and management is not on yet" and ACTIVE
        // is "management is on". Swapping them would make the moment the
        // controller enables management unreadable, which is the one decision
        // still hanging off the flat machine.
        $this->assertFalse(StateTreeService::agrees('READY', 'STATE_ACTIVE'));
        $this->assertFalse(StateTreeService::agrees('ACTIVE', 'STATE_DFS_READY'));
    }

    public function testTheTreeSaysNoLessThanTheFlatMachine(): void
    {
        $flat = new \ReflectionClass(AccessPointState::class);
        $tree = new \ReflectionClass(NodeState::class);
        $flatStates = array_filter(array_keys($flat->getConstants()),
            fn ($c) => str_starts_with($c, 'STATE_'));
        $treeStates = array_filter(array_keys($tree->getConstants()),
            fn ($c) => str_starts_with($c, 'AP_'));
        $this->assertGreaterThanOrEqual(count($flatStates), count($treeStates),
            'the tree must be able to say everything the flat machine can, or replacing it loses something');
    }
}
