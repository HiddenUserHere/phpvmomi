<?php
namespace DNJ\PHPVMOMI\ManagedObjects\actions;

use SoapVar;
use DNJ\PHPVMOMI\API;
use DNJ\PHPVMOMI\Exception;
use DNJ\PHPVMOMI\DataObjects\DynamicData;
use DNJ\PHPVMOMI\DataObjects\OptionValue;
use DNJ\PHPVMOMI\DataObjects\VirtualMachineConfigSpec;
use DNJ\PHPVMOMI\DataObjects\VirtualMachineFileInfo;
use DNJ\PHPVMOMI\DataObjects\VirtualCdromAtapiBackingInfo;
use DNJ\PHPVMOMI\DataObjects\VirtualCdromIsoBackingInfo;
use DNJ\PHPVMOMI\DataObjects\VirtualCdrom;
use DNJ\PHPVMOMI\DataObjects\VirtualE1000;
use DNJ\PHPVMOMI\DataObjects\VirtualVmxnet3;
use DNJ\PHPVMOMI\DataObjects\VirtualAHCIController;
use DNJ\PHPVMOMI\DataObjects\VirtualEthernetCard;
use DNJ\PHPVMOMI\DataObjects\VirtualDisk;
use DNJ\PHPVMOMI\DataObjects\VirtualIDEController;
use DNJ\PHPVMOMI\DataObjects\VirtualDiskFlatVer2BackingInfo;
use DNJ\PHPVMOMI\DataObjects\VirtualDeviceConfigSpec;
use DNJ\PHPVMOMI\DataObjects\VirtualPS2Controller;
use DNJ\PHPVMOMI\DataObjects\VirtualDeviceDeviceBackingInfo;
use DNJ\PHPVMOMI\DataObjects\VirtualUSBController;
use DNJ\PHPVMOMI\DataObjects\VirtualLsiLogicController;
use DNJ\PHPVMOMI\DataObjects\VirtualMachineVideoCard;
use DNJ\PHPVMOMI\DataObjects\VirtualSIOController;
use DNJ\PHPVMOMI\DataObjects\VirtualPCIController;
use DNJ\PHPVMOMI\DataObjects\VirtualMachineBootOptions;
use DNJ\PHPVMOMI\DataObjects\VirtualMachineDefaultPowerOpInfo;
use DNJ\PHPVMOMI\DataObjects\VirtualMachineFlagInfo;
use DNJ\PHPVMOMI\DataObjects\ToolsConfigInfo;

use DNJ\PHPVMOMI\Exceptions\BadCallMethod;
use DNJ\PHPVMOMI\Exceptions\RequiredConfigException;
use DNJ\PHPVMOMI\Exceptions\UnexpectedValueConfigException;
use DNJ\PHPVMOMI\ManagedObjects\Task;
use DNJ\PHPVMOMI\ManagedObjects\VirtualMachine;
use DNJ\PHPVMOMI\ManagedObjects\Datastore;
use DNJ\PHPVMOMI\ManagedObjects\Custom\File;

trait VirtualMachineTrait
{
	public function byID(string $id): self
	{
		$vm = $this->api->getPropertyCollector()->_RetrieveProperties(array(
			'propSet' => array(
				'type' => 'VirtualMachine',
				'all' => true,
				'pathSet' => array(
					'name',
					'runtime',
					'config',
					'datastore',
					'guest',
					'summary',
					'effectiveRole',
				)
			),
			'objectSet' => array(
				'obj' => array(
					'type' => 'VirtualMachine',
					'_' => $id
				),
				'skip' => false
			),
		));
		return self::fromAPI($this->api, $vm->returnval, $this);
	}

	public static function list(API $api): array
	{
		$ss1 = new SoapVar(array ('name' => 'FolderTraversalSpec'), SOAP_ENC_OBJECT, null, null, 'selectSet', null);
		$ss2 = new SoapVar(array ('name' => 'DataCenterVMTraversalSpec'), SOAP_ENC_OBJECT, null, null, 'selectSet', null);
		$a = array ('name' => 'FolderTraversalSpec', 'type' => 'Folder', 'path' => 'childEntity', 'skip' => false, $ss1, $ss2);
		$ss = new SoapVar(array ('name' => 'FolderTraversalSpec'), SOAP_ENC_OBJECT, null, null, 'selectSet', null);
		$b = array ('name' => 'DataCenterVMTraversalSpec', 'type' => 'Datacenter', 'path' => 'vmFolder', 'skip' => false, $ss);

		$response = $api->getPropertyCollector()->_RetrieveProperties(array(
			'propSet' => array(
				'type' => 'VirtualMachine',
				'all' => false,
				'pathSet' => array(
					'name',
					'runtime',
					'config',
					'datastore',
					'guest',
					'summary',
					'effectiveRole',
					'resourceConfig',
					'resourcePool',
				)
			),
			'objectSet' => array(
				'obj' => $api->getServiceContent()->rootFolder,
				'skip' => false,
				'selectSet' => array(
					new SoapVar($a, SOAP_ENC_OBJECT, 'TraversalSpec'),
					new SoapVar($b, SOAP_ENC_OBJECT, 'TraversalSpec'),
				),
			),
		))->returnval;

		if (!is_array($response)) {
			$response = [$response];
		}
		$virtualMachines = [];
		foreach($response as $vm){
			$virtualMachines[] = VirtualMachine::fromAPI($api, $vm);
		}
		return $virtualMachines;
	}

	private static function fromAPI(API $api, DynamicData $response, VirtualMachine $vm = null): VirtualMachine
	{
		
		if ($vm === null) {
			$vm = new self($api);
		}
		$vm->id = $response->obj->_;
		$runtime = self::getPropertyByName('runtime', $response->propSet);
		$vm->name = self::getPropertyByName('name', $response->propSet);
		$vm->config = self::getPropertyByName('config', $response->propSet);
		$vm->summary = self::getPropertyByName('summary', $response->propSet);
		$vm->guest = self::getPropertyByName('guest', $response->propSet);
		$vm->runtime = self::array2Object(array(
			'connectionState' => $runtime->connectionState,
			'powerState' => $runtime->powerState,
			'toolsInstallerMounted' => $runtime->toolsInstallerMounted,
			'bootTime' => isset($runtime->bootTime) ? $runtime->bootTime : null,
			'memoryOverhead' => $runtime->memoryOverhead ?? null,
			'maxCpuUsage' => $runtime->maxCpuUsage,
			'maxMemoryUsage' => $runtime->maxMemoryUsage
		));
		$vm->setAPI($api);
		return $vm;
	}

	private static function getPropertyByName(string $name, array $propset)
	{
		foreach($propset as $prop){
			if($prop->name == $name ){
				return $prop->val;
			}
		}
		return null;
	}

	private static function array2Object(array $array): DynamicData
	{
		$new = new DynamicData;
		foreach($array as $key => $value){
			if(is_array($value)){
				$value = self::array2Object($value);
			}
			$new->$key = $value;
		}
		return $new;
	}

	private static function getGuestsID(): array
	{
		return array(
			"asianux3Guest", "asianux3_64Guest", "asianux4Guest", "asianux4_64Guest", "centosGuest", "centos64Guest", "debian4Guest", "debian4_64Guest", "debian5Guest", "debian5_64Guest", "debian6Guest", "debian6_64Guest", "debian7Guest", "debian7_64Guest", "oesGuest", "oracleLinuxGuest", "oracleLinux64Guest", "other24xLinuxGuest", "other24xLinux64Guest", "other26xLinuxGuest", "other26xLinux64Guest", "other3xLinuxGuest", "other3xLinux64Guest", "otherLinuxGuest", "otherLinux64Guest", "rhel2Guest", "rhel3Guest", "rhel3_64Guest", "rhel4Guest", "rhel4_64Guest", "rhel5Guest", "rhel5_64Guest", "rhel6Guest", "rhel6_64Guest", "rhel7Guest", "rhel7_64Guest", "sles10Guest", "sles10_64Guest", "sles11Guest", "sles11_64Guest", "sles12Guest", "sles12_64Guest", "slesGuest", "sles64Guest", "ubuntuGuest", "ubuntu64Guest",
			"darwinGuest", "darwin64Guest", "darwin10Guest", "darwin10_64Guest", "darwin11Guest", "darwin11_64Guest", "darwin12_64Guest", "darwin13_64Guest",
			"freebsdGuest", "freebsd64Guest", "os2Guest", "netware5Guest", "netware6Guest", "solaris10Guest", "solaris10_64Guest", "solaris11_64Guest", "otherGuest", "otherGuest64", "openServer5Guest", "openServer6Guest", "unixWare7Guest", "eComStationGuest", "eComStation2Guest", "solaris8Guest", "solaris9Guest", "vmkernelGuest", "vmkernel5Guest",
			"dosGuest", "winNetBusinessGuest", "windows9Guest", "windows9_64Guest", "win2000AdvServGuest", "win2000ProGuest", "win2000ServGuest", "win31Guest", "windows7Guest", "windows7_64Guest", "windows8Guest", "windows8_64Guest", "win95Guest", "win98Guest", "winNTGuest", "winNetEnterpriseGuest", "winNetEnterprise64Guest", "winNetDatacenterGuest", "winNetDatacenter64Guest", "winNetStandardGuest", "winNetStandard64Guest", "winNetWebGuest", "winLonghornGuest", "winLonghorn64Guest", "windows7Server64Guest", "windows8Server64Guest", "windows9Server64Guest", "winVistaGuest", "winVista64Guest", "winXPProGuest", "winXPPro64Guest"
		);
	}

	private static function NetworkCardType(): array
	{
		return array("VirtualE1000", "VirtualSriovEthernetCard", "VirtualVmxnet3");
	}

	private static function checkDisk(array $disk): array
	{
		if(!isset($disk['capacity'])){
			throw new RequiredConfigException('hardware[disk][capacity]');
		}
		if(!is_numeric($disk['capacity']) or $disk['capacity'] <= 0){
			throw new UnexpectedValueConfigException('hardware[disk][capacity]');
		}
		
		if(!isset($disk['datastore'])){
			throw new RequiredConfigException('hardware[disk][datastore]');
		}
		if(!$disk['datastore'] instanceof Datastore){
			throw new UnexpectedValueConfigException('hardware[disk][datastore]');
		}
		if(!isset($disk['thinProvisioned'])){
			$disk['thinProvisioned'] = true;
		}
		if(!is_bool($disk['thinProvisioned'])){
			throw new UnexpectedValueConfigException('hardware[disk][thinProvisioned]');
		}
		return $disk;
	}

	private static function checkNetwork(array $network): array
	{
		if(!isset($network['type'])){
			$network['type'] = 'VirtualE1000';
		}
		if(!in_array($network['type'], self::NetworkCardType())){
			throw new UnexpectedValueConfigException('hardware[net][type]');
		}
		if(!isset($network['addressType'])){
			$network['addressType'] = 'generated';
		}
		if(!in_array($network['addressType'], ['generated', 'manual'])){
			throw new UnexpectedValueConfigException('hardware[net][addressType]');
		}
		if($network['addressType'] == 'manual'){
			if(!isset($network['macAddress'])){
				throw new RequiredConfigException('hardware[disk][macAddress]');
			}
			if(!preg_match("/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/", $network['macAddress'])){
				throw new UnexpectedValueConfigException('hardware[net][macAddress]');
			}
		}else{
			$network['macAddress'] = '';
		}
		if(!isset($network['connected'])){
			$network['connected'] = true;
		}
		if(!is_bool($network['connected'])){
			throw new UnexpectedValueConfigException('hardware[net][connected]');
		}
		return $network;
	}

	private static function checkCDrom(array $cdrom): array
	{
		if(isset($cdrom['iso'])){
			if(!$cdrom['iso'] instanceof File){
				throw new UnexpectedValueConfigException('hardware[cd][iso]');
			}
		}
		if(!isset($cdrom['connected'])){
			$cdrom['connected'] = true;
		}
		if(!is_bool($cdrom['connected'])){
			throw new UnexpectedValueConfigException('hardware[cdrom][connected]');
		}
		return $cdrom;
	}

	private static function checkBootOptions($boot)
	{
		if($boot === null){
			$boot = array();
		}
		if(isset($boot['delay'])){
			if(!is_numeric($boot['delay'])){
				throw new UnexpectedValueConfigException('boot[delay]');
			}
		}else{
			$boot['delay'] = 0;
		}
		if(isset($boot['enterBIOSSetup'])){
			if(!is_bool($boot['enterBIOSSetup'])){
				throw new UnexpectedValueConfigException('boot[enterBIOSSetup]');
			}
		}else{
			$boot['enterBIOSSetup'] = false;
		}
		if(isset($boot['retry'])){
			if(!is_bool($boot['retry'])){
				throw new UnexpectedValueConfigException('boot[retry]');
			}
		}else{
			$boot['retry'] = false;
		}
		if(isset($boot['retryDelay'])){
			if(!is_bool($boot['retryDelay'])){
				throw new UnexpectedValueConfigException('boot[retryDelay]');
			}
		}else{
			$boot['retryDelay'] = false;
		}
		return $boot;
	}

	private static function createConfig(array $config)
	{
		$result = new VirtualMachineConfigSpec();
		$result->name = $config['name'];
		$result->version = $config['version'];
		$result->npivTemporaryDisabled = true;
		$result->guestId = $config['guest'];
		$result->files = new VirtualMachineFileInfo();
			$result->files->vmPathName = $config['location']->__toString();
		$result->tools = new ToolsConfigInfo();
			$result->tools->afterPowerOn = true;
			$result->tools->afterResume = true;
			$result->tools->beforeGuestStandby = true;
			$result->tools->beforeGuestShutdown = true;
			$result->tools->toolsUpgradePolicy = 'manual';
			$result->tools->syncTimeWithHost = false;
		$result->flags = new VirtualMachineFlagInfo();
			$result->flags->disableAcceleration = false;
			$result->flags->enableLogging = true;
			$result->flags->monitorType = 'release';
			$result->flags->virtualMmuUsage = 'automatic';
			$result->flags->virtualExecUsage = 'hvAuto';
		$result->powerOpInfo = new VirtualMachineDefaultPowerOpInfo();
			$result->powerOpInfo->powerOffType = 'preset';
			$result->powerOpInfo->suspendType = 'soft';
			$result->powerOpInfo->resetType = 'preset';
			$result->powerOpInfo->defaultPowerOffType = "hard";
		$result->numCPUs = $config["hardware"]["numCPU"];
		if(isset($config["hardware"]["numCoresPerSocket"])){
			$result->numCoresPerSocket = $config["hardware"]["numCoresPerSocket"];
		}
		$result->memoryMB = $config['hardware']['memory'];
		$result->memoryHotAddEnabled = $config['hardware']['memoryHotAdd'];
		$result->cpuHotAddEnabled = $config['hardware']['cpuHotAdd'];
		$result->deviceChange = array();
		$devices = self::predefinedDevices();
		if(isset($config['hardware']['disk'])){
			$devices[] = self::createDiskDevice($config);
		}
		if(isset($config['hardware']['net'])){
			$devices[] = self::createNetDevice($config);
		}
		if(isset($config['hardware']['cdrom'])){
			$devices[] = self::createCdromDevice($config);
		}
		for($x = 0;$x < count($devices);$x++){
			$type = get_class($devices[$x]);
			if(($pos = strrpos($type, "\\")) !== false){
				$type = substr($type, $pos + 1);
			}

			$deviceChange = new VirtualDeviceConfigSpec();
			$deviceChange->operation = 'add';
			if($type == 'VirtualDisk'){
				$deviceChange->fileOperation = 'create';
			}
			$deviceChange->device = new SoapVar($devices[$x], SOAP_ENC_OBJECT, $type);
			$result->deviceChange[] = $deviceChange;
		}
		$result->swapPlacement = "inherit";
		$result->bootOptions = new VirtualMachineBootOptions();
		$result->bootOptions->bootDelay = 0;
			$result->bootOptions->enterBIOSSetup = false;
			$result->bootOptions->bootRetryEnabled = false;
			$result->bootOptions->bootRetryDelay = 10;
		$result->vPMCEnabled = new SoapVar(false, XSD_BOOLEAN);
		$result->firmware = "bios";
		$result->maxMksConnections = -1;
		$result->nestedHVEnabled = false;
		return $result;
	}

	private static function predefinedDevices(): array
	{
		$devices = [];
		$device = new VirtualIDEController();
		$device->key = 200;
		$device->connectable = new DynamicData;
			$device->connectable->startConnected = true;
			$device->connectable->allowGuestControl = true;
			$device->connectable->connected = true;
		$device->busNumber = 0;
		$device->device = -1000001;
		$devices[] = $device;


		$device = new VirtualIDEController();
		$device->key = 201;
		$device->connectable = new DynamicData;
			$device->connectable->startConnected = true;
			$device->connectable->allowGuestControl = true;
			$device->connectable->connected = true;
		$device->busNumber = 1;
		$device->device = -1000001;
		$devices[] = $device;


		$device = new VirtualPCIController();
		$device->key = 100;
		$device->connectable = new DynamicData;
			$device->connectable->startConnected = true;
			$device->connectable->allowGuestControl = true;
			$device->connectable->connected = true;
		$device->busNumber = 0;
		$device->device = -1000001;
		$devices[] = $device;


		$device = new VirtualPS2Controller();
		$device->key = 300;
		$device->connectable = new DynamicData;
			$device->connectable->startConnected = true;
			$device->connectable->allowGuestControl = true;
			$device->connectable->connected = true;
		$device->busNumber = 0;
		$device->device = -1000001;
		$devices[] = $device;


		$device = new VirtualSIOController();
		$device->key = 400;
		$device->connectable = new DynamicData;
			$device->connectable->startConnected = true;
			$device->connectable->allowGuestControl = true;
			$device->connectable->connected = true;
		$device->busNumber = 0;
		$device->device = -1000001;
		$devices[] = $device;

		$device = new VirtualMachineVideoCard();
		$device->key = -51;
		$device->unitNumber = -50;
		$device->videoRamSizeInKB = 4 * 1024;
		$device->numDisplays = 1;
		$device->useAutoDetect = false;
		$device->enable3DSupport = false;
		$devices[] = $device;


		$device = new VirtualLsiLogicController();
		$device->key = -904;
		$device->busNumber = 0;
		$device->sharedBus = 'noSharing';
		$devices[] = $device;


		$device = new VirtualAHCIController();
		$device->key = -690;
		$device->busNumber = 0;
		$devices[] = $device;

		$device = new VirtualUSBController();
		$device->key = -50;
		$device->busNumber = 0;
		$devices[] = $device;
		return $devices;
	}

	private static function createDiskDevice($config): VirtualDisk
	{
		$device = new VirtualDisk();
		$device->key = -1000000;
		$device->backing = new VirtualDiskFlatVer2BackingInfo;
			$device->backing->fileName = $config['hardware']['disk']['datastore']->file($config['name'] . "/{$config['name']}0.vmdk")->__toString();
			$device->backing->datastore = new SoapVar($config['hardware']['disk']['datastore']->__toString(), XSD_STRING, 'Datastore');
			
			$device->backing->diskMode = 'persistent';
			$device->backing->thinProvisioned = $config['hardware']['disk']['thinProvisioned'];
			$device->backing->eagerlyScrub = false;
		$device->backing = new SoapVar($device->backing, SOAP_ENC_OBJECT, 'VirtualDiskFlatVer2BackingInfo');
		$device->controllerKey = -904;
		$device->unitNumber = 0;
		$device->capacityInKB = $config['hardware']['disk']['capacity'] * 1024;
		$device->capacityInBytes = $config['hardware']['disk']['capacity'] * 1024 * 1024;
		return $device;
	}

	private static function createNetDevice($config): VirtualEthernetCard
	{
		$device = null;
		switch ($config['hardware']['net']['type']) {
			case self::vmxnet3:
				$device = new VirtualVmxnet3();
				break;
			case self::e1000:
				$device = new VirtualE1000();
				break;
			default:
				throw new Exception("createNetDevice: unsupported device (" . $config['hardware']['net']['type'] . ")");
		}

		$device->key = -50;
		$device->backing = new VirtualDeviceDeviceBackingInfo;
			$device->backing->deviceName = $config["hardware"]["net"]["deviceName"];
			$device->backing->network = new SoapVar('HaNetwork-VM Network', SOAP_ENC_OBJECT, 'Network');

		$device->backing = new SoapVar($device->backing, SOAP_ENC_OBJECT, 'VirtualEthernetCardNetworkBackingInfo');
		$device->connectable = new DynamicData;
			$device->connectable->startConnected = true;
			$device->connectable->allowGuestControl = true;
			$device->connectable->connected = $config['hardware']['net']['connected'];
		$device->addressType = $config['hardware']['net']['addressType'];
		$device->macAddress = $config['hardware']['net']['macAddress'];
		return $device;
	}

	private static function createCdromDevice($config): VirtualCdrom
	{
		$device = new VirtualCdrom();
		$device->key = -1000001;
		if(isset($config['hardware']['cdrom']['iso'])){
			$device->backing = new VirtualCdromIsoBackingInfo;
				$device->backing->fileName = $config['hardware']['cdrom']['iso']->__toString();

			$device->backing = new SoapVar($device->backing, SOAP_ENC_OBJECT, 'VirtualCdromIsoBackingInfo');
		}else{
			$device->backing = new VirtualCdromAtapiBackingInfo;
				$device->backing->deviceName = "Cdrom";
				$device->backing->useAutoDetect = false;

			$device->backing = new SoapVar($device->backing, SOAP_ENC_OBJECT, 'VirtualCdromAtapiBackingInfo');
		}
		$device->connectable = new DynamicData;
			$device->connectable->startConnected = true;
			$device->connectable->allowGuestControl = true;
			$device->connectable->connected = $config['hardware']['net']['connected'];
		$device->controllerKey = -690;
		$device->unitNumber = 0;
		return $device;
	}

	private static function createExtraConfig(array $config): array
	{
		$result = array();
		foreach ($config as $key => $value) {
			$option = new OptionValue();
			$option->key = $key;
			$option->value = new SoapVar($value, XSD_STRING, 'string');
			$result[] = $option;
		}
		return $result;
	}

	private static function editConfig(array $config)
	{
		$result = new VirtualMachineConfigSpec();
		$result->version = $config["version"];
		$result->extraConfig = self::createExtraConfig($config["extraConfig"]);
		$result->swapPlacement = "inherit";
		$result->firmware = "bios";
		$result->maxMksConnections = 40;
		$result->guestAutoLockEnabled = false;
		return $result;
	}

	public function on(): Task
	{
		if (empty($this->id)) {
			throw new BadCallMethod('Can not call method: ' . __CLASS__ . '@' . __FUNCTION__ . '! ID is not setted!');
		}
		return $this->_PowerOnVM_Task();
	}

	public function off(): Task
	{
		if (empty($this->id)) {
			throw new BadCallMethod('Can not call method: ' . __CLASS__ . '@' . __FUNCTION__ . '! ID is not setted!');
		}
		return $this->_PowerOffVM_Task();
	}

	public function reset(): Task
	{
		if (empty($this->id)) {
			throw new BadCallMethod('Can not call method: ' . __CLASS__ . '@' . __FUNCTION__ . '! ID is not setted!');
		}
		return $this->_ResetVM_Task();
	}

	public function suspend(): Task
	{
		if (empty($this->id)) {
			throw new BadCallMethod('Can not call method: ' . __CLASS__ . '@' . __FUNCTION__ . '! ID is not setted!');
		}
		return $this->_SuspendVM_Task();
	}

	public function destroy(): Task
	{
		if (empty($this->id)) {
			throw new BadCallMethod('Can not call method: ' . __CLASS__ . '@' . __FUNCTION__ . '! ID is not setted!');
		}

		$response = $this->_Destroy_Task(array(
			'type' => 'VirtualMachine',
			'_' => $this->id,
		));
		return $this->api->getTask()->byID($response->returnval->_);
	}

	public function reboot(): Task
	{
		if (empty($this->id)) {
			throw new BadCallMethod('Can not call method: ' . __CLASS__ . '@' . __FUNCTION__ . '! ID is not setted!');
		}
		return $this->_RebootGuest();
	}

	public function shutdown(): Task
	{
		if (empty($this->id)) {
			throw new BadCallMethod('Can not call method: ' . __CLASS__ . '@' . __FUNCTION__ . '! ID is not setted!');
		}
		return $this->_ShutdownGuest();
	}

	public function unregister(): Task
	{
		if (empty($this->id)) {
			throw new BadCallMethod('Can not call method: ' . __CLASS__ . '@' . __FUNCTION__ . '! ID is not setted!');
		}
		return $this->_UnregisterVM();
	}

	public function isOn(): bool
	{
		return (isset($this->runtime->powerState) and $this->runtime->powerState == 'poweredOn');
	}

	public function isOff(): bool
	{
		return (isset($this->runtime->powerState) and $this->runtime->powerState == 'poweredOff');
	}

	public function isSuspend(): bool
	{
		return (isset($this->runtime->powerState) and $this->runtime->powerState == 'suspend');
	}

	public function create(array $config)
	{
		if (!isset($config['name'])) {
			throw new RequiredConfigException('name');
		}
		if (!isset($config['version'])) {
			$config['version'] = $this->lastVersion();
		}
		if(preg_match("/vmx-(\d+)/", $config['version'], $matches)){
			if($matches[1] < 7 or $matches[1] > 14){
				throw new UnexpectedValueConfigException('version');
			}
		}
		if(isset($config['guest'])){
			if(!in_array($config['guest'], self::getGuestsID())){
				throw new UnexpectedValueConfigException('guest');
			}
		}else{
			throw new RequiredConfigException('guest');
		}
		if(isset($config['guest'])){
			if(!in_array($config['guest'], self::getGuestsID())){
				throw new UnexpectedValueConfigException('guest');
			}
		}else{
			throw new RequiredConfigException('guest');
		}

		if(isset($config['hardware']['memory'])){
			if(!is_numeric($config['hardware']['memory'])){
				throw new UnexpectedValueConfigException('hardware[memory]');
			}
			$config['hardware']['memory'] = $config['hardware']['memory'];
		}else{
			throw new RequiredConfigException('hardware[memory]');
		}


		if(isset($config['hardware']['numCPU'])){
			if(!is_numeric($config['hardware']['numCPU'])){
				throw new UnexpectedValueConfigException('hardware[numCPU]');
			}
			$config['hardware']['numCPU'] = $config['hardware']['numCPU'];
		}else{
			throw new RequiredConfigException('hardware[numCPU]');
		}

		if(isset($config['hardware']['memoryHotAdd'])){
			if(!is_bool($config['hardware']['memoryHotAdd'])){
				throw new UnexpectedValueConfigException('hardware[memoryHotAdd]');
			}
		}else{
			$config['hardware']['memoryHotAdd'] = false;
		}

		if(isset($config['hardware']['cpuHotAdd'])){
			if(!is_bool($config['hardware']['cpuHotAdd'])){
				throw new UnexpectedValueConfigException('hardware[cpuHotAdd]');
			}
		}else{
			$config['hardware']['cpuHotAdd'] = false;
		}
		if(isset($config['hardware']['disk'])){
			$config['hardware']['disk'] = self::checkDisk($config['hardware']['disk']);
		}
		if(isset($config['hardware']['net'])){
			$config['hardware']['net'] = self::checkNetwork($config['hardware']['net']);
		}
		if(isset($config['hardware']['cdrom'])){
			$config['hardware']['cdrom'] = self::checkCDrom($config['hardware']['cdrom']);
		}
		$config['boot'] = self::checkBootOptions(isset($config['boot']) ? $config['boot'] : null);
		$response = $this->api->getClient()->CreateVM_Task(array(
			'_this' => array(
				'type' => 'Folder',
				'_' => 'ha-folder-vm'
			),
			'config' => self::createConfig($config),
			'pool' => array(
				'_' => 'ha-root-pool',
				'type' => 'ResourcePool'
			)
		));
		return $this->api->getTask()->byID($response->returnval->_);
	}

	private function lastVersion(): string
	{
		$versionCode = $this->api->getServiceContent()->about->version;

		if (preg_match("/^(\d+)\.(\d+)/", $versionCode, $matches)) {
			$major =  $matches[1];
			$minor =  $matches[2];
			if ($major < 5) {
				return 'vmx-07';
			} elseif($major == 5) {
				if ($minor == 0) {
					return 'vmx-08';
				} elseif($minor == 1) {
					return 'vmx-09';
				} elseif($minor == 5) {
					return 'vmx-10';
				}
			} elseif($major == 6) {
				if ($minor == 0) {
					return 'vmx-11';
				} elseif($minor == 5) {
					return 'vmx-13';
				}
			}
		}
		return '';
	}
}