<?php 

namespace Nodes;

class Doctype extends Node {
	public $value;

	public function __construct($value) {
		$this->value = $value;
	}
}
