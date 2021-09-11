<?php

namespace ApManBundle\Service;

class WifiIeParser {
	private $logger;

	function __construct(
		\Psr\Log\LoggerInterface $logger
	) {
		$this->logger = $logger;
	}
    
	/**
	* get Tags of Wifi Information Elements
	* @return array
	*
	* example IEs
	* $raw = '00054D47617374010402040B1632080C1218243048606C03010D2D1A631017FF000000000000000000000000000000000000000000007F080100080000000040DD690050F204104A000110103A000100100800023148104700107D46C36A37DD5A419F9586291E436C69105400080000000000000000103C00010310020002000010090002000010120002000010210001201023000120102400012010110001201049000600372A000120DD11506F9A0902020025000605005858045101DD080050F20800110000DD09001018020100100000';
	* $raw = '0000010802040B160C12182432043048606C2D1AE70917FFFF000000000000000000000000000000000000000000';
	* $raw = '0000010482848B9632080C1218243048606C03010D2D1A2D401BFF000000000000000000000000000000000000000000007F080000080400000040FF1C23010808180080203002000D009F08000000FDFFFDFF391CC7711C07';
	* $raw = '00054D4761737401080C1218243048606C2D1A630017FF000000000000000000000000000000000000000000007F080100080000000040DD690050F204104A000110103A000100100800023148104700107D46C36A37DD5A419F9586291E436C69105400080000000000000000103C00010310020002000010090002000010120002000010210001201023000120102400012010110001201049000600372A000120DD11506F9A0902020025000605005858045101DD080050F208000E0000DD09001018020100100000';
	* $raw = '0000010802040B160C12182432043048606C03010D2D1A2C011AFF000000000000000000480001000000000000000000007F0800000A0201000040DD7E0050F204104A000110103A0001001008000242881047001011A9F8244FF85A9DBA93807AFB27389210540008000A0050F2040005103C00010110020002000010090002000010120002000010210007416D6C6F676963102300074D58512050726F102400074D58512050726F10110004703231321049000600372A000120DD0D506F9A0A00000600101C440032DD11506F9A0902020025000605004E5A045106';
	* $raw = '0000010402040B1632080C1218243048606C03010D2D1A2D001BFFFF0000000000000000000000000000000000000000007F0A00004800004000400021FF03020028FF1C23030808180080203000000D009F080C0000F5FFF5FF391CC7711C07DD1300904C0408BF0C3278810FFAFF0000FAFF0000DD070050F208001300DD0A00101802000010000000DD0A506F9A16030101650101';
	*/
	public function parseInformationElements(string $ies) {
		$tags = [];
		$pos = 0;
		while (true)  {
			$tagId = ord($ies[$pos]);
			$pos++;
			$tagLength = ord($ies[$pos]);
			$pos++;
			$tagValue = substr($ies, $pos, $tagLength);
			$pos+= $tagLength;
			// Vendor Tags 
			if ($tagId == 221) {
				if (!array_key_exists(221, $tags)) $tags[221] = [];
				$tags[221][] = $tagValue;
			} else {
				$tags[$tagId] = $tagValue;
			}
			
			if ($pos > strlen($ies)-2) break;
		}
		return $tags;
	}

	/**
	* get Extended Capabilities
	* @return array 
	*/
	public function getExtendedCapabilities(array $tags) {
		$extCaps = [];
		if (!isset($tags[127])) return $extCaps;
		$raw = $tags[127];
		$length = strlen($raw);
		if ($length!= 8) return $extCaps;
		if (ord($raw[2]) && 0b1 == 0b1) {
			$extCaps[] = '20/40 BSS Coexistence Management Support';
		}
		if (ord($raw[2]) && 0b1000 == 0b1000) {
			$extCaps[] = 'BSS Transition';
		}
		if (ord($raw[2]) && 0b10 == 0b10) {
			$extCaps[] = 'WNM Sleep Mode';
		}
		if (ord($raw[3]) && 0b100 == 0b100) {
			$extCaps[] = 'DMS';
		}
		if (ord($raw[4]) && 0b1 == 0b1) {
			$extCaps[] = 'QoS Map';
		}
		if (ord($raw[7]) && 0b100000 == 0b100000) {
			$extCaps[] = 'Operating Mode Notification';
		}
		/*
		printf("X %08b\n", ord($raw[0]));
		printf("X %08b\n", ord($raw[1]));
		printf("X %08b\n", ord($raw[2]));
		printf("X %08b\n", ord($raw[3]));
		printf("X %08b\n", ord($raw[4]));
		printf("X %08b\n", ord($raw[5]));
		printf("X %08b\n", ord($raw[6]));
		printf("X %08b\n", ord($raw[7]));
		*/
		return $extCaps;
	}

	public function getResolveIeNames(array $tags) {
		$ie_map = [];
		$ie_map[0]="SSID";
		$ie_map[1]="SUPP_RATES";
		$ie_map[3]="DS_PARAMS";
		$ie_map[4]="CF_PARAMS";
		$ie_map[5]="TIM";
		$ie_map[6]="IBSS_PARAMS";
		$ie_map[7]="COUNTRY";
		$ie_map[10]="REQUEST";
		$ie_map[11]="BSS_LOAD";
		$ie_map[12]="EDCA_PARAM_SET";
		$ie_map[13]="TSPEC";
		$ie_map[14]="TCLAS";
		$ie_map[15]="SCHEDULE";
		$ie_map[16]="CHALLENGE";
		$ie_map[32]="PWR_CONSTRAINT";
		$ie_map[33]="PWR_CAPABILITY";
		$ie_map[34]="TPC_REQUEST";
		$ie_map[35]="TPC_REPORT";
		$ie_map[36]="SUPPORTED_CHANNELS";
		$ie_map[37]="CHANNEL_SWITCH";
		$ie_map[38]="MEASURE_REQUEST";
		$ie_map[39]="MEASURE_REPORT";
		$ie_map[40]="QUIET";
		$ie_map[41]="IBSS_DFS";
		$ie_map[42]="ERP_INFO";
		$ie_map[43]="TS_DELAY";
		$ie_map[44]="TCLAS_PROCESSING";
		$ie_map[45]="HT_CAP";
		$ie_map[46]="QOS";
		$ie_map[48]="RSN";
		$ie_map[51]="AP_CHANNEL_REPORT";
		$ie_map[52]="NEIGHBOR_REPORT";
		$ie_map[53]="RCPI";
		$ie_map[54]="MOBILITY_DOMAIN";
		$ie_map[55]="FAST_BSS_TRANSITION";
		$ie_map[56]="TIMEOUT_INTERVAL";
		$ie_map[57]="RIC_DATA";
		$ie_map[58]="DSE_REGISTERED_LOCATION";
		$ie_map[59]="SUPPORTED_OPERATING_CLASSES";
		$ie_map[61]="HT_OPERATION";
		$ie_map[62]="SECONDARY_CHANNEL_OFFSET";
		$ie_map[63]="BSS_AVERAGE_ACCESS_DELAY";
		$ie_map[64]="ANTENNA";
		$ie_map[65]="RSNI";
		$ie_map[66]="MEASUREMENT_PILOT_TRANSMISSION";
		$ie_map[67]="BSS_AVAILABLE_ADM_CAPA";
		$ie_map[68]="BSS_AC_ACCESS_DELAY";
		$ie_map[69]="TIME_ADVERTISEMENT";
		$ie_map[70]="RRM_ENABLED_CAPABILITIES";
		$ie_map[71]="MULTIPLE_BSSID";
		$ie_map[72]="20_40_BSS_COEXISTENCE";
		$ie_map[73]="20_40_BSS_INTOLERANT";
		$ie_map[74]="OVERLAPPING_BSS_SCAN_PARAMS";
		$ie_map[75]="RIC_DESCRIPTOR";
		$ie_map[76]="MMIE";
		$ie_map[78]="EVENT_REQUEST";
		$ie_map[79]="EVENT_REPORT";
		$ie_map[80]="DIAGNOSTIC_REQUEST";
		$ie_map[81]="DIAGNOSTIC_REPORT";
		$ie_map[82]="LOCATION_PARAMETERS";
		$ie_map[83]="NONTRANSMITTED_BSSID_CAPA";
		$ie_map[84]="SSID_LIST";
		$ie_map[85]="MULTIPLE_BSSID_INDEX";
		$ie_map[86]="FMS_DESCRIPTOR";
		$ie_map[87]="FMS_REQUEST";
		$ie_map[88]="FMS_RESPONSE";
		$ie_map[89]="QOS_TRAFFIC_CAPABILITY";
		$ie_map[90]="BSS_MAX_IDLE_PERIOD";
		$ie_map[91]="TFS_REQ";
		$ie_map[92]="TFS_RESP";
		$ie_map[93]="WNMSLEEP";
		$ie_map[94]="TIM_BROADCAST_REQUEST";
		$ie_map[95]="TIM_BROADCAST_RESPONSE";
		$ie_map[96]="COLLOCATED_INTERFERENCE_REPORT";
		$ie_map[97]="CHANNEL_USAGE";
		$ie_map[98]="TIME_ZONE";
		$ie_map[99]="DMS_REQUEST";
		$ie_map[100]="DMS_RESPONSE";
		$ie_map[101]="LINK_ID";
		$ie_map[102]="WAKEUP_SCHEDULE";
		$ie_map[104]="CHANNEL_SWITCH_TIMING";
		$ie_map[105]="PTI_CONTROL";
		$ie_map[106]="TPU_BUFFER_STATUS";
		$ie_map[107]="INTERWORKING";
		$ie_map[108]="ADV_PROTO";
		$ie_map[109]="EXPEDITED_BANDWIDTH_REQ";
		$ie_map[110]="QOS_MAP_SET";
		$ie_map[111]="ROAMING_CONSORTIUM";
		$ie_map[112]="EMERGENCY_ALERT_ID";
		$ie_map[113]="MESH_CONFIG";
		$ie_map[114]="MESH_ID";
		$ie_map[115]="MESH_LINK_METRIC_REPORT";
		$ie_map[116]="CONGESTION_NOTIFICATION";
		$ie_map[117]="PEER_MGMT";
		$ie_map[118]="MESH_CHANNEL_SWITCH_PARAMETERS";
		$ie_map[119]="MESH_AWAKE_WINDOW";
		$ie_map[120]="BEACON_TIMING";
		$ie_map[121]="MCCAOP_SETUP_REQUEST";
		$ie_map[122]="MCCAOP_SETUP_REPLY";
		$ie_map[123]="MCCAOP_ADVERTISEMENT";
		$ie_map[124]="MCCAOP_TEARDOWN";
		$ie_map[125]="GANN";
		$ie_map[126]="RANN";
		$ie_map[130]="PREQ";
		$ie_map[131]="PREP";
		$ie_map[132]="PERR";
		$ie_map[137]="PXU";
		$ie_map[138]="PXUC";
		$ie_map[139]="AMPE";
		$ie_map[140]="MIC";
		$ie_map[141]="DESTINATION_URI";
		$ie_map[142]="U_APSD_COEX";
		$ie_map[143]="DMG_WAKEUP_SCHEDULE";
		$ie_map[144]="EXTENDED_SCHEDULE";
		$ie_map[145]="STA_AVAILABILITY";
		$ie_map[146]="DMG_TSPEC";
		$ie_map[147]="NEXT_DMG_ATI";
		$ie_map[148]="DMG_CAPABILITIES";
		$ie_map[151]="DMG_OPERATION";
		$ie_map[152]="DMG_BSS_PARAMETER_CHANGE";
		$ie_map[153]="DMG_BEAM_REFINEMENT";
		$ie_map[154]="CHANNEL_MEASUREMENT_FEEDBACK";
		$ie_map[156]="CCKM";
		$ie_map[157]="AWAKE_WINDOW";
		$ie_map[158]="MULTI_BAND";
		$ie_map[159]="ADDBA_EXTENSION";
		$ie_map[160]="NEXTPCP_LIST";
		$ie_map[161]="PCP_HANDOVER";
		$ie_map[162]="DMG_LINK_MARGIN";
		$ie_map[163]="SWITCHING_STREAM";
		$ie_map[164]="SESSION_TRANSITION";
		$ie_map[165]="DYNAMIC_TONE_PAIRING_REPORT";
		$ie_map[166]="CLUSTER_REPORT";
		$ie_map[167]="REPLAY_CAPABILITIES";
		$ie_map[168]="RELAY_TRANSFER_PARAM_SET";
		$ie_map[169]="BEAMLINK_MAINTENANCE";
		$ie_map[170]="MULTIPLE_MAC_SUBLAYERS";
		$ie_map[171]="U_PID";
		$ie_map[172]="DMG_LINK_ADAPTATION_ACK";
		$ie_map[174]="MCCAOP_ADVERTISEMENT_OVERVIEW";
		$ie_map[175]="QUIET_PERIOD_REQUEST";
		$ie_map[177]="QUIET_PERIOD_RESPONSE";
		$ie_map[181]="QMF_POLICY";
		$ie_map[182]="ECAPC_POLICY";
		$ie_map[183]="CLUSTER_TIME_OFFSET";
		$ie_map[184]="INTRA_ACCESS_CATEGORY_PRIORITY";
		$ie_map[185]="SCS_DESCRIPTOR";
		$ie_map[186]="QLOAD_REPORT";
		$ie_map[187]="HCCA_TXOP_UPDATE_COUNT";
		$ie_map[188]="HIGHER_LAYER_STREAM_ID";
		$ie_map[189]="GCR_GROUP_ADDRESS";
		$ie_map[190]="ANTENNA_SECTOR_ID_PATTERN";
		$ie_map[191]="VHT_CAP";
		$ie_map[192]="VHT_OPERATION";
		$ie_map[193]="VHT_EXTENDED_BSS_LOAD";
		$ie_map[194]="VHT_WIDE_BW_CHSWITCH";
		$ie_map[195]="TRANSMIT_POWER_ENVELOPE";
		$ie_map[196]="VHT_CHANNEL_SWITCH_WRAPPER";
		$ie_map[197]="VHT_AID";
		$ie_map[198]="VHT_QUIET_CHANNEL";
		$ie_map[199]="VHT_OPERATING_MODE_NOTIFICATION";
		$ie_map[200]="UPSIM";
		$ie_map[201]="REDUCED_NEIGHBOR_REPORT";
		$ie_map[202]="TVHT_OPERATION";
		$ie_map[204]="DEVICE_LOCATION";
		$ie_map[205]="WHITE_SPACE_MAP";
		$ie_map[206]="FTM_PARAMETERS";
		$ie_map[213]="S1G_BCN_COMPAT";
		$ie_map[216]="TWT";
		$ie_map[217]="S1G_CAPABILITIES";
		$ie_map[221]="VENDOR_SPECIFIC";
		$ie_map[232]="S1G_OPERATION";
		$ie_map[237]="CAG_NUMBER";
		$ie_map[239]="AP_CSN";
		$ie_map[240]="FILS_INDICATION";
		$ie_map[241]="DILS";
		$ie_map[242]="FRAGMENT";
		$ie_map[244]="RSNX";
		$ie_map[255]="EXTENSION";

		$ieList = [];
		foreach ($tags as $tagId => $tagValue) {
			if (!array_key_exists($tagId, $ie_map)) continue;
			$ieList[$tagId] = $ie_map[$tagId];
		}
		return $ieList;
	}
}
