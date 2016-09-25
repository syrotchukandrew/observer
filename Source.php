<?php

require_once('Logger.php');
require_once('Mailer.php');

//abstract base class for in-memory representation of various business entities.  The only item
//we have implemented at this point is InventoryItem (see below).
abstract class Entity
{
    static protected $defaultEntityManager = null;
    protected $data = null;
    protected $em = null;
    protected $entityName = null;
    protected $id = null;

    public function init()
    {
    }

    abstract public function getMembers();

    abstract public function getPrimary();

    //setter for properies and items in the underlying data array
    public function __set($variableName, $value)
    {
        if (array_key_exists($variableName, ($this->getMembers()))) {
            $newData = $this->data;
            $newData[$variableName] = $value;
            $this->update($newData);
            $this->data = $newData;
        } else {
            if (property_exists($this, $variableName)) {
                $this->$variableName = $value;
            } else {
                throw new Exception("Set failed. Class " . get_class($this) .
                    " does not have a member named " . $variableName . ".");
            }
        }
    }

    //getter for properies and items in the underlying data array
    public function __get($variableName)
    {
        if (array_key_exists($variableName, ($this->getMembers()))) {
            $data = $this->read();
            return $data[$variableName];
        } else {
            if (property_exists($this, $variableName)) {
                return $this->$variableName;
            } else {
                throw new Exception("Get failed. Class " . get_class($this) .
                    " does not have a member named " . $variableName . ".");
            }
        }
    }

    static public function setDefaultEntityManager($em)
    {
        self::$defaultEntityManager = $em;
    }

    //Factory function for making entities.
    static public function getEntity($entityName, $data, $entityManager = null)
    {
        $em = $entityManager === null ? self::$defaultEntityManager : $entityManager;
        $entity = $em->create($entityName, $data);
        $entity->init();
        return $entity;
    }

    static public function getDefaultEntityManager()
    {
        return self::$defaultEntityManager;
    }

    public function create($entityName, $data)
    {
        $entity = self::getEntity($entityName, $data);
        return $entity;
    }

    public function read()
    {
        return $this->data;
    }

    public function update($newData)
    {
        $this->em->update($this, $newData);
        $this->data = $newData;
    }

    public function delete()
    {
        $this->em->delete($this);
    }
}

//Helper function for printing out error information
function getLastError()
{
    $errorInfo = error_get_last();
    $errorString = " Error type {$errorInfo['type']}, {$errorInfo['message']} on line {$errorInfo['line']} of " .
        "{$errorInfo['file']}. ";
    return $errorString;
}

//A super-simple replacement class for a real database, just so we have a place for storing results.
class DataStore
{
    protected $storePath = null;
    protected $dataStore = array();

    public function __construct($storePath)
    {
        $this->storePath = $storePath;
        if (!file_exists($storePath)) {
            if (!touch($storePath)) {
                throw new Exception("Could not create data store file $storePath. Details:" . getLastError());
            }
            if (!chmod($storePath, 0777)) {
                throw new Exception("Could not set read/write on data store file $storePath. " .
                    "Details:" . getLastError());
            }
        }
        if (!is_readable($storePath) || !is_writable($storePath)) {
            throw new Exception("Data store file $storePath must be readable/writable. Details:" . getLastError());
        }
        $rawData = file_get_contents($storePath);

        if ($rawData === false) {
            throw new Exception("Read of data store file $storePath failed.  Details:" . getLastError());
        }
        if (strlen($rawData > 0)) {
            $this->dataStore = unserialize($rawData);
        } else {
            $this->dataStore = null;
        }
    }

    //update the store with information
    public function set($item, $primary, $data)
    {
        $foundItem = null;
        $this->dataStore[$item][$primary] = $data;
    }

    //get information
    public function get($item, $primary)
    {
        if (isset($this->dataStore[$item][$primary])) {
            return $this->dataStore[$item][$primary];
        } else {
            return null;
        }
    }

    //delete an item.
    public function delete($item, $primary)
    {
        if (isset($this->dataStore[$item][$primary])) {
            unset($this->dataStore[$item][$primary]);
        }
    }

    //save everything
    public function save()
    {
        $result = file_put_contents($this->storePath, json_encode($this->dataStore) . "\r\n", FILE_APPEND);
        if ($result === null) {
            throw new Exception("Write of data store file $this->storePath failed.  Details:" . getLastError());
        }
    }

    //Which types of items do we have stored
    public function getItemTypes()
    {
        if (is_null($this->dataStore)) {
            return array();
        }
        return array_keys($this->dataStore);
    }

    //get keys for an item-type, so we can loop over.
    public function getItemKeys($itemType)
    {
        return array_keys($this->dataStore[$itemType]);
    }
}

//This class managed in-memory entities and communicates with the storage class (DataStore in our case).
class EntityManager implements \SplSubject
{
    private $observers = array();
    protected $entities = array();
    protected $entityIdToPrimary = array();
    protected $entityPrimaryToId = array();
    protected $entitySaveList = array();
    protected $nextId = null;
    protected $dataStoreClass = null;

    public function __construct($storePath)
    {
        $this->dataStoreClass = new DataStore($storePath);

        $this->nextId = 1;

        $itemTypes = $this->dataStoreClass->getItemTypes();
        foreach ($itemTypes as $itemType) {
            $itemKeys = $this->dataStoreClass->getItemKeys($itemType);
            foreach ($itemKeys as $itemKey) {
                $entity = $this->create($itemType, $this->dataStoreClass->get($itemType, $itemKey), true);
            }
        }
    }

    public function attach (\SplObserver $observer)
    {
        $this->observers[] = $observer;
    }

    public function detach (\SplObserver $observer)
    {
        $key = array_search($observer,$this->observers, true);
        if($key){
            unset($this->observers[$key]);
        }
    }

    public function notify ($changedData = null)
    {
        foreach ($this->observers as $value) {
            $value->update($this, $changedData);
        }
    }

    //create an entity
    public function create($entityName, $data, $fromStore = false)
    {
        $entity = new $entityName;
        $entity->entityName = $entityName;
        $entity->data = $data;
        $entity->em = Entity::getDefaultEntityManager();
        $id = $entity->id = $this->nextId++;
        $this->entities[$id] = $entity;
        $primary = $data[$entity->getPrimary()];
        $this->entityIdToPrimary[$id] = $primary;
        $this->entityPrimaryToId[$primary] = $id;
        if ($fromStore !== true) {
            $this->entitySaveList[] = $id;
        }

        return $entity;
    }

    //update
    public function update($entity, $newData)
    {
        if ($newData === $entity->data) {
            //Nothing to do
            return $entity;
        }

        $this->entitySaveList[] = $entity->id;
        $oldPrimary = $entity->{$entity->getPrimary()};
        $newPrimary = $newData[$entity->getPrimary()];
        if ($oldPrimary != $newPrimary) {
            $this->dataStoreClass->delete(get_class($entity), $oldPrimary);
            unset($this->entityPrimaryToId[$oldPrimary]);
            $this->entityIdToPrimary[$entity->id] = $newPrimary;
            $this->entityPrimaryToId[$newPrimary] = $entity->id;
        }
        $changedData = array(
            'sku' => $entity->sku,
            'new_qoh' => $newData['qoh'],
            'old_qoh' => $entity->qoh
        );

        $entity->data = $newData;

        $this->notify($changedData);

        return $entity;
    }

    //Delete
    public function delete($entity)
    {
        $id = $entity->id;
        $entity->id = null;
        $entity->data = null;
        $entity->em = null;
        $this->entities[$id] = null;
        $primary = $entity->{$entity->getPrimary()};
        $this->dataStoreClass->delete(get_class($entity), $primary);
        unset($this->entityIdToPrimary[$id]);
        unset($this->entityPrimaryToId[$primary]);
        return null;
    }

    public function findByPrimary($primary)
    {
        if (isset($this->entityPrimaryToId[$primary])) {
            $id = $this->entityPrimaryToId[$primary];
            return $this->entities[$id];
        } else {
            return null;
        }
    }

    //Update the datastore to update itself and save.
    public function updateStore()
    {
        foreach ($this->entitySaveList as $id) {
            $entity = $this->entities[$id];
            $this->dataStoreClass->set(get_class($entity), $entity->{$entity->getPrimary()}, $entity->data);
        }
        $this->dataStoreClass->save();
    }
}

//An example entity, which some business logic.  we can tell inventory items that they have shipped or been received
//in
class InventoryItem extends Entity
{
    //Update the number of items, because we have shipped some.
    public function itemsHaveShipped($numberShipped)
    {
        $newData = $this->data;
        $current = $this->qoh;

        for ($i = 1; $i <= $numberShipped; $i++) {
            //notifyWareHouse();  //Not implemented yet.
            $newData['qoh'] = --$current;
        }
        $this->update($newData);
    }

    //We received new items, update the count.
    public function itemsReceived($numberReceived)
    {
        $newData = $this->data;
        $current = $this->qoh;

        for ($i = 1; $i <= $numberReceived; $i++) {
            //notifyWareHouse();  //Not implemented yet.
            $newData['qoh'] = ++$current;
        }
        $this->update($newData);
    }

    public function changeSalePrice($salePrice)
    {
        $newData = $this->data;
        $newData['salePrice'] = $salePrice;
        $this->update($newData);
    }

    public function getMembers()
    {
        //These are the field in the underlying data array
        return array("sku" => 1, "qoh" => 1, "cost" => 1, "salePrice" => 1);
    }

    public function getPrimary()
    {
        //Which field constitutes the primary key in the storage class?
        return "sku";
    }
}

function driver()
{
    $dataStorePath = "data_store_file.data";
    $entityManager = new EntityManager($dataStorePath);
    $logger = new Logger();
    $mailer = new Mailer();
    $entityManager->attach($logger);
    $entityManager->attach($mailer);
    Entity::setDefaultEntityManager($entityManager);
    //create five new Inventory items

    $item1 = Entity::getEntity('InventoryItem',
        array('sku' => 'abc-4589', 'qoh' => 0, 'cost' => '5.67', 'salePrice' => '7.27'));
    $item2 = Entity::getEntity('InventoryItem',
        array('sku' => 'hjg-3821', 'qoh' => 0, 'cost' => '7.89', 'salePrice' => '12.00'));
    $item3 = Entity::getEntity('InventoryItem',
        array('sku' => 'xrf-3827', 'qoh' => 0, 'cost' => '15.27', 'salePrice' => '19.99'));
    $item4 = Entity::getEntity('InventoryItem',
        array('sku' => 'eer-4521', 'qoh' => 0, 'cost' => '8.45', 'salePrice' => '1.03'));
    $item5 = Entity::getEntity('InventoryItem',
        array('sku' => 'qws-6783', 'qoh' => 0, 'cost' => '3.00', 'salePrice' => '4.97'));

    $item1->itemsReceived(4);
    $item2->itemsReceived(2);
    $item3->itemsReceived(12);
    $item4->itemsReceived(20);
    $item5->itemsReceived(1);

    $item3->itemsHaveShipped(5);
    $item4->itemsHaveShipped(16);

    $item4->changeSalePrice(0.87);

    $entityManager->updateStore();

    $entityManager->detach($logger);
    $entityManager->detach($mailer);
}

driver();