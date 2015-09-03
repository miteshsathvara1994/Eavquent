<?php
namespace Devio\Propertier\Services;

use Illuminate\Support\Collection;
use Devio\Propertier\Models\Property;
use Illuminate\Database\Eloquent\Model;
use Devio\Propertier\Models\PropertyValue;
use Devio\Propertier\Exceptions\PropertyIsNotMultivalue;

class ValueSetter
{
    /**
     * Entity instance.
     *
     * @var Model
     */
    protected $entity;

    /**
     * Returns a new ValueSetter instance.
     *
     * @param Model $entity
     *
     * @return static
     */
    public static function make(Model $entity)
    {
        return (new static)->entity($entity);
    }

    /**
     * Assign a new entity.
     *
     * @param Model $entity
     *
     * @return $this
     */
    public function entity(Model $entity)
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * Assign a value to a property.
     *
     * @param $key
     * @param $value
     *
     * @return PropertyValue|mixed|void
     */
    public function set($key, $value)
    {
        $property = $this->getProperty($key);

        if ($property->isMultivalue())
        {
            return $this->assignMany($property, $value);
        }

        return $this->assignOne($property, $value);
    }

    /**
     * Asign a value to the property value model. First will look
     * for that value, if it does exist, will change its value,
     * otherwise it will create a new value model.
     *
     * @param $property
     * @param $value
     *
     * @return PropertyValue|mixed
     */
    protected function assignOne($property, $value)
    {
        if ($propertyValue = $this->getValues($property, true))
        {
            $propertyValue->value = $value;
        }
        // If the value does not exist into the database, will create
        // a new instance related to the property and add it to the
        // property values collection waiting to be persisted.
        else
        {
            $propertyValue = $this->createNewValue($property, $value);
        }

        // Will set the property relation property. This will help to avoid infinite
        // pointing loops that the method "relationsToArray" will cause if a model
        // relation is pointing its own parent. This is only for accessing the
        // PropertyValue Property object without making a new database call.
        $this->loadPropertyRelation($propertyValue, $property);

        return $propertyValue;
    }

    /**
     * Will assign multiple values to the same property. Any previous values
     * stored will be queued for deletion and replaced for the new ones.
     *
     * @param Property $property
     * @param          $valueCollection
     *
     * @throws PropertyIsNotMultivalue
     */
    protected function assignMany(Property $property, $valueCollection)
    {
        if ( ! $valueCollection instanceof Collection || ! is_array($valueCollection))
        {
            $valueCollection = new Collection($valueCollection);
        }

        // Any existing value will be added to the value deletion queue that
        // will be processed after saving. Meanwhile, the new values will
        // be created as new and added to the current values relation.
        $this->clearAndQueuePropertyValues($property);

        foreach ($valueCollection as $value)
        {
            $this->createNewValue($property, $value);
        }
    }

    /**
     * Will clear the property values relation and queue every value
     * for deletion in case the value is finally saved.
     *
     * @param Property $property
     */
    protected function clearAndQueuePropertyValues(Property $property)
    {
        $currentValues = $this->getValues($property);
        $this->queueForDeletion($currentValues);

        // Once the current property values are queued to be deleted, we have
        // to remove them from the property as they were already loaded in
        // the property relation. Just replace with an empty collection.
        $property->load(['values' => function ()
        {
            return new Collection();
        }]);
    }

    /**
     * Creates a new property value related to the given property
     * and the entity.
     *
     * @param $property
     * @param $value
     *
     * @return PropertyValue
     */
    protected function createNewValue($property, $value)
    {
        $propertyValue = new PropertyValue([
            'value'       => $value,
            'entity_type' => $this->entity->getMorphClass(),
            'entity_id'   => $this->entity->id,
            'property_id' => $property->id
        ]);

        // After creating a new property value, we have to include it manually
        // into the property values relation collection. The "push" method
        // inlcuded in the collection will help us to perform this task.
        $property->values->push($propertyValue);

        return $propertyValue;
    }

    /**
     * Add items to the deletion queue.
     *
     * @param $valueCollection
     */
    protected function queueForDeletion($valueCollection)
    {
        $valueCollection->each(function ($value)
        {
            $this->entity->queueValueForDeletion($value);
        });
    }

    /**
     * Will manually set the relationship to the property passed
     * as argument.
     *
     * @param $propertyValue
     * @param $property
     */
    protected function loadPropertyRelation($propertyValue, $property)
    {
        $propertyValue->load(['property' => function () use ($property)
        {
            return $property;
        }]);
    }

    /**
     * Provides the property value model as collection or single element.
     *
     * @param      $property
     * @param bool $single
     *
     * @return mixed
     */
    protected function getValues($property, $single = false)
    {
        $values = $property->values;

        return ! $single ? $values : $values->first();
    }

    /**
     * Find a property by key in the properties collection.
     *
     * @param $key
     *
     * @return Property
     */
    protected function getProperty($key)
    {
        return $this->entity->getPropertyObject($key);
    }
}