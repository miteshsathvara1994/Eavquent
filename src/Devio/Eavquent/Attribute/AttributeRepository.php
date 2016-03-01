<?php

namespace Devio\Eavquent\Attribute;


class AttributeRepository
{
    /**
     * Will return all attributes.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function all()
    {
        return Attribute::all();
    }
}