<?php 

namespace Nodes;

class Each extends Node
{
	public $obj;
	public $value;
	public $key;
	public $block;
	
	function __construct($obj, $value, $key, $block)
	{
		$this->obj = $obj;
		$this->value = $value;
		$this->key = $key;
		$this->block = $block;
	}
}
