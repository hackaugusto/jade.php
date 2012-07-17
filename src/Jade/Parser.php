<?php

namespace Jade;

class Parser {

    public $basepath;
	public $extension = '.jade';
	public $textOnly = array('script','style');

    protected $input;
    protected $lexer;
    protected $filename;
    protected $blocks = array();
    protected $mixins = array();
	protected $context = array();

    public function __construct(String $str, String $filename) {
		$this->input = $str;
        $this->lexer = new Lexer($str);
		$this->filename = $filename;
		array_push($this->context, $this);
    }

	public function context($parser=null) {
		if ($parser===null) {
			return array_pop($this->context);
		}
		array_push($this->context, $parser);
	}

	public function advance() {
		return $this->lexer->advance();
	}

	public function skip($n) {
		while($n--) $this->advance();
	}

	public function peek() {
		return $this->lookahead(1);
	}

	public function line() {
		return $this->lexer->lineno;
	}

	public function lookahead($n) {
		return $this->lexer->lookahead($n);
	}

    public function parse() {
        $block = new \Nodes\Block();
		$block->line = $this->line();

        while ($this->peek()->type !== 'eos') {

            if ($this->peek()->type === 'newline') {
                $this->advanced();
			}
			else
			{
                $block->push($this->parseExpression());
            }
        }

		if ($parser = $this->extending) {
			$this->context($parser);
			$ast = $parser->parse();
			$this->context();

			foreach ($this->mixins as $name => $v) {
				$ast->unshift($this->mixins[$name]);
			}
			return $ast;
		}

        return $block;
    }

    protected function expect($type) {
        if ($this->peek()->type === $type) {
            return $this->lexer->advance();
        }

        throw new \Exception(sprintf('Expected %s, but got %s', $type, $this->peek()->type));
    }

    protected function accept($type) {
        if ($this->peek()->type === $type) {
            return $this->advance();
        }
    }

    protected function parseExpression() {
		$_types = array('tag','mixin','block','case','when','default','extends','include','doctype','filter','comment','text','each','code','call','interpolation');

		if (in_array($this->peek()->type, $_types)) {
			$_method = 'parse' . ucfirst($this->peek()->type);
			return $this->$_method();
		}

        switch ( $this->peek()->type ) {
            case 'yield':
                $this->advance();
				$block = new \Nodes\Block();
				$block->yield = true;
				return $block;

			case 'id':
			case 'class':
                $token = $this->advance();
                $this->lexer->defer($this->lexer->token('tag', 'div'));
                $this->lexer->defer($token);
                return $this->parseExpression();

			default:
				throw new \Exception('Unexcpected token "' . $this->peek()->type . '"');
        }
    }

    protected function parseText($trim = false) {
        $token = $this->expect('text');
        $node = new \Nodes\Text($token->value);
		$node->line = $this->line();
		return $node;
    }

	protected function parseBlockExpansion() {
		if (':' == $this->peek()->type) {
			$this->advance();
			return new \Nodes\Block($this->parseExpression());
		}

		return $this->parseBlock();
	}

	protected function parseCase() {
		$value = $this->expect('case')->value;
		$node = new \Nodes\CaseNode($value);
		$node->line = $this->line();
		$node->block = $this->parseBlock();
		return $node;
	}

	protected function parseWhen() {
		$value = $this->expect('when')->value;
		return new \Nodes\When($value, $this->parseBlockExpansion());
	}

	protected function parseDefault() {
		$this->expect('default');
		return new \Nodes\When('default', $this->parseBlockExpansion());
	}

    protected function parseCode() {
        $token  = $this->expect('code');
        $node   = new \Nodes\Code($token->value, $token->buffer, $token->escape);
		$node->line = $this->line();

		$i = 1;
        while ($this->lookahead($i)->type === 'newline') {
			$i++;
        }

        if ($this->lookahead($i)->type === 'indent') {
			$this->skip($i-1);
            $node->block = $this->parseBlock();
        }

        return $node;
    }

    protected function parseComment() {
        $token  = $this->expect('comment');

        if ($this->peek()->type === 'indent') {
			$node = new \Nodes\BlockComment($token->value, $this->parseBlock(), $token->buffer);
		}else{
			$node = new \Nodes\Comment($token->value, $token->buffer);
		}
		$node->line = $this->line();

        return $node;
    }

    protected function parseDoctype() {
        $token = $this->expect('doctype');
        $node =  new \Nodes\Doctype($token->value);
		$node->line = $this->line();
		return $node;
    }

    protected function parseFilter() {
        $token      = $this->expect('filter');
        $attributes = $this->accept('attributes');

		$this->lexer->pipeless = true;
        $block		= $this->parseTextBlock();
		$this->lexer->pipeless = false;

        $node = new \Nodes\Filter($token->value, $block, $attributes);
        $node->line = $this->line();
        return $node;
    }

	protected function parseASTFilter() {
		$token = $this->expect('tag');
		$attributes = $this->accept('attributes');
		$this->expect(':');
		$block = $this->parseBlock();

		$node = new \Nodes\Filter($token->value, $block, $attributes);
		$node->line = $this->line();
		return $node;
	}

	protected function parseEach() {
		$token = $this->expect('each');
		$node = new \Nodes\Each($token->code, $token->value, $token->key);
		$node->line = $this->line();
		$node->block = $this->parseBlock();
		return $node;
	}

	protected function parseExtends() {

		$file = $this->expect('extends')->value;
		$dir = realpath(dirname($this->filename));
		$path = $dir . DIRECTORY_SEPARATOR . $path . $this->extension;

		$string = file_get_contents($path);
		$parser = new Parser($string, $path);
		$parser->blocks = $this->blocks;
		$parser->contexts = $this->contexts;
		$this->extending = $parser;

		return new \Nodes\Literal('');
	}

	protected function parseBlock() {
		$block = $this->expect('block');
		$mode = $block->mode;
		$name = trim($block->value);

		$block = 'indent' == $this->peek()->type ? $this->parseBlock() : new \Nodes\Block(new \Node\Literal(''));
		$prev = $this->blocks[$name];

		if ($prev) {
			switch ($prev->mode) {
				case 'append':
					$block->nodes = $block->nodes->concat($prev->nodes);
					$prev = $block;
					break;

				case 'prepend':
					$block->nodes = $prev->nodes->concat($block->nodes);
					$prev = $block;
					break;
			}

			$this->blocks[$name] = $prev;
		}else{
			$this->blocks[$name] = $block;
		}

		$block->mode = $mode;
		return $this->blocks[$name];
	}

    protected function parseInclude() {
        $token = $this->expect('include');
		$file = trim($token->value);
		$dir = realpath(basename($this->filename));

		if( strpos(basename($file), '.') === false ){
			$file = $file . '.jade';
		}

		$path = $dir . DIRECTORY_SEPARATOR . $file;
		$str = file_get_contents($path);

		if ('.jade' != substr($file,-5)) {
			return new \Nodes\Literal($str);
		}

        $parser = new Parser($str, $path);
		$parser->blocks = $this->blocks;
		$parser->mixins = $this->mixins;

		$this->context($parser);
		$ast = $parser->parse();
		$this->context();
		$ast->filename = $path;

		if ('indent' == $this->peek()->type) {
			$ast->includeBlock()->push($this->parseBlock());
		}

		return $ast;
    }

	protected function parseCall() {
		$token = $this->expect('call');
		$name = $token->value;
		$arguments = $token->arguments;
		$mixin = new \Nodes\Mixin($name, $arguments, new \Node\Block(), true);

		$this->tag($mixin);

		if ($mixin->block->isEmpty()) {
			$mixin->block = null;
		}

		return $mixin;
	}

	protected function parseMixin() {
		$token = $this->expect('mixin');
		$name = $token->value;
		$arguments = $token->arguments;

		// definition
		if ('indent' == $this->peek()->type) {
			$mixin = new \Nodes\Mixin($name, $arguments, $this->parseBlock(), false);
			$this->mixins[$name] = $mixin;
			return $mixin;
		// call
		}else{
			return new \Nodes\Mixin($name, $arguments, null, true);
		}
	}

    protected function parseTextBlock() {
        $block = new \Nodes\Block();
		$block->line = $this->line();
		$spaces = $this->expect('indent')->value;

		if (!isset($this->_spaces)) {
			$this->_spaces = $spaces;
		}

		$indent = str_repeat(' ', $spaces - $this->_spaces+1);

        while ($this->peek()->type != 'outdent') {

            switch ($this->peek()->type) {
				case 'newline':
					$this->lexer->advance();
					break;

				case 'indent':
					foreach ($this->parseTextBlock()->nodes as $n) {
						$block->push($n);
					}
					break;

				default:
					$text = new \Nodes\Text($indent . $this->advance()->value);
					$text->line = $this->line();
					$block->push($text);
			}
        }

		if (isset($this->_spaces) && $spaces == $this->_spaces) {
			unset($this->_spaces);
		}

        $this->expect('outdent');
        return $block;
    }

    protected function parseBlock() {
        $block = new \Nodes\Block();
		$block->line = $this->line();
        $this->expect('indent');

        while ($this->peek()->type !== 'outdent' ) {

            if ($this->peek()->type === 'newline') {
                $this->lexer->advance();
            }else{
                $block->push($this->parseExpression());
            }
        }

        $this->expect('outdent');
        return $block;
    }

	protected function parseInterpolation() {
		$token = $this->advance();
		$tag = new \Nodes\Tag($token->value);
		$tag->buffer = true;
		return $this->tag($tag);
	}

	protected function parseTag() {
		$i=2;

		if ('attributes' == $this->lookahead($i)->type) {
			$i++;
		}

		if (':' == $this->lookahead($i)->type) {
			$i++;

			if ('indent' == $this->lookahead($i)->type) {
				return $this->parseASTFilter();
			}
		}

		$token = $this->advance();
		$tag = new \Nodes\Tag($token->value);
		$tag->selfClosing = $token->selfClosing;
		
		return $this->tag($tag);
	}

	protected function tag($tag) {
		$tag->line = $this->line();

		out:
			while (true) {
				switch ($this->peek()->type) {
					case 'id':
					case 'class':
						$token = $this->advance();
						$tag->setAttribute($token->type, "'" . $token->value . "'");
						continue;

					case 'attributes':
						$token = $this->advance();
						$obj = $token->attributes;
						$escaped = $token->escaped;
						$keys = array_keys($obj);

						if ($token->selfClosing) {
							$tag->selfClosing = true;
						}

						foreach ($keys as $k) {
							$value = $obj[$k];
							$tag->setAttribute($k, $value, $escaped[$name]);
						}
						continue;

					default:
						break out;
				}
			}

		if ('.' == $this->peek()->value) {
			$dot = $tag->textOnly = true;
			$this->advance();
		}

		switch ($this->peek()->type) {
			case 'text':
				$tag->block->push($this->parseText());
				break;

			case 'code':
				$tag->code = $this->parseCode();
				break;

			case ':':
				$this->advance();
				$tag->block = new \Nodes\Block();
				$tag->block->push($this->parseExpression());
				break;
		}

		while ('newline' == $this->peek()->type) {
			$this->advance();
		}

		if (in_array($tag->name, $this->textOnly)) {
			$tag->textOnly = true;
		}

		if ('script' == $tag->name) {
			$type = $tag->getAttribute('type');

			if (!dot && $type && 'text/javascript' != preg_replace('/^[\'\"]|[\'\"]$/','',$type)) {
				$tag->textOnly = false;
			}
		}

		if ('indent' == $this->peek()->type) {
			if ($tag->textOnly) {
				$this->lexer->pipeless = true;
				$tag->block = $this->parseTextBlock();
				$this->lexer->pipeless = false;
			}else{
				$block = $this->block();
				if ($tag->block) {
					foreach ($block->nodes as $n) {
						$tag->block->push($n);
					}
				}else{
					$tag->block = $block;
				}
			}
		}

		return $tag;
	}
}
