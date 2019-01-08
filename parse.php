<?php

namespace AST;
use \Exception;
use \InvalidArgumentException;
use \LogicException;
use \RuntimeException;

class TokenDefinition {
    private $_representation = null;
    private $_name = null;
    private $_matchRegex = null;

    public function __construct($representation, $name=null, $matchRegex=null) {
        $this->_representation = $representation;
        if ($name === null) {
            $name = $representation;
        }
        $this->_name = $name;
        if ($matchRegex === null) {
            $matchRegex = '/' . preg_quote($representation) . '/';
        }
        $this->_matchRegex = $matchRegex;
    }

    public function representation() { return $this->_representation;     }
    public function name() { return $this->_name; }

    public function tryMatch($text, $position) {
        $matches = null;
        $result = preg_match($this->_matchRegex, $text, $matches, PREG_OFFSET_CAPTURE, $position);
        if ($result === 0) {
            return null;
        } elseif ($result === false) {
            throw new InvalidArgumentException('An error occurred in preg_match.');
        } elseif (count($matches) == 0) {
            throw new RuntimeException('No matches?');
        }
        list($matchTxt, $matchOfs) = $matches[0];
        if ($matchOfs > $position) {
            return null;
        }
        return $matchTxt;
    }
    public function __toString() {
        return '<' . $this->name() . ">\n";
    }
}

class TokenInstance {
    private $_definition = null;
    private $_text = null;
    private $_position = null;
    private $_length = null;

    public function __construct($definition, $text, $position, $length) {
        $this->_definition = $definition;
        $this->_text = $text;
        $this->_position = $position;
        $this->_length = $length;
    }

    public function definition() { return $this->_definition; }
    public function text() { return $this->_text; }
    public function position() { return $this->_position; }
    public function length() { return $this->_length; }
    public function match() { return substr($this->_text, $this->position(), $this->length()); }

    public function __toString() {
        return '<' . $this->definition()->name() . ':' . $this->match() . ">\n";
    }
}


class UnknownTokenException extends \Exception {
    private $_text = null;
    private $_position = null;

    public function __construct($text, $position, $code = 0, Exception $previous = null) {
        $this->_text = $text;
        $this->_position = $position;
        $message = 'Unknown token "' . substr($text, $position, 4) . '" at position ' . $position;
        parent::__construct($message, $code, $previous);
    }

    public function getText() { return $this->_text; }
    public function getPosition() { return $this->_position; }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

class NotEnoughArgumentsException extends \Exception {
    private $_elementDefinition = null;
    private $_firstTokenInstance = null;

    public function __construct($elementDefinition, $firstTokenInstance, $code = 0, Exception $previous = null) {
        $this->_elementDefinition = $elementDefinition;
        $this->_firstTokenInstance = $firstTokenInstance;
        $message = 'Not enough arguments for operator ' . $elementDefinition->name()
            . ' encountered at position ' . $firstTokenInstance->position() . ', around "'
            . substr($firstTokenInstance->text(), max(0, $firstTokenInstance->position() - 3), $firstTokenInstance->length() + 3)
            . '".';
        if ($elementDefinition->arity() > 0) {
            $message .= ' Expected ' . $elementDefinition->arity() . ' arguments.';
        }
        parent::__construct($message, $code, $previous);
    }

    public function getFirstTokenInstance() { return $this->_firstTokenInstance; }
    public function getElementDefinition() { return $this->_elementDefinition; }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

class StrayTokenException extends \Exception {
    private $_tokenInstance = null;

    public function __construct($tokenInstance, $code = 0, Exception $previous = null) {
        $this->_tokenInstance = $tokenInstance;
        $message = 'Stray token encountered at position ' . $tokenInstance->position() . ', around "'
            . substr($tokenInstance->text(), max(0, $tokenInstance->position() - 3), $tokenInstance->length() + 3)
            . '".';
        parent::__construct($message, $code, $previous);
    }

    public function getTokenInstance() { return $this->_tokenInstance; }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

class UnmatchedWrapperException extends \Exception {
    private $_elementDefinition = null;
    private $_firstTokenInstance = null;

    public function __construct($elementDefinition, $firstTokenInstance, $code = 0, Exception $previous = null) {
        $this->_elementDefinition = $elementDefinition;
        $this->_firstTokenInstance = $firstTokenInstance;
        $message = 'Unmatched opening token ' . $elementDefinition->tokenDefs()[0] . ' for wrapping operator '
            . $elementDefinition->name() . ' encountered at position ' . $firstTokenInstance->position() . ', around "'
            . substr($firstTokenInstance->text(), max(0, $firstTokenInstance->position() - 3), $firstTokenInstance->length() + 3)
            . '". The missing closing token is ' . $elementDefinition->tokenDefs()[1] . '.';
        parent::__construct($message, $code, $previous);
    }

    public function getFirstTokenInstance() { return $this->_firstTokenInstance; }
    public function getElementDefinition() { return $this->_elementDefinition; }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

abstract class Fixing {
    const None = 0;
    const Prefix = 1;
    const Postfix = 2;
    const Infix = 3;
    const Wrap = 4;
}

class ElementInstance {
    private $_definition = null;
    private $_args = null;

    public function __construct($definition, $args) {
        $this->_definition = $definition;
        $this->_args = $args;
    }

    public function definition() { return $this->_definition; }
    public function args() { return $this->_args; }

    public function isFullyExpanded() {
        if ($this->definition() !== null && $this->definition()->fixing() == Fixing::None) {
            return true;
        }
        foreach ($this->args() as $arg) {
            if ($arg instanceof TokenInstance || !$arg->isFullyExpanded()) {
                return false;
            }
        }
        return true;
    }

    public function recursiveExpand($elmDef) {
        if ($this->isFullyExpanded()) {
            return;
        }
        $elmDef->spliceInstancesIn($this->_args);
        foreach ($this->args() as $arg) {
            if ($arg instanceof ElementInstance && !$arg->isFullyExpanded()) {
                $arg->recursiveExpand($elmDef);
            }
        }
    }

    public function recursiveFindUnexpandedToken() {
        if ($this->isFullyExpanded()) {
            return null;
        }
        foreach ($this->args() as $arg) {
            if ($arg instanceof TokenInstance) {
                return $arg;
            } else {
                $tok = $arg->recursiveFindUnexpandedToken();
                if ($tok !== null) {
                    return $tok;
                }
            }
        }
        throw LogicException('A fully expanded element instance has no stray token!');
    }

    public function recursivePrint($indent=0) {
        if ($this->definition() !== null) {
            echo str_repeat('  ', $indent) . $this->definition()->name() . "\n";
        }
        foreach ($this->args() as $arg) {
            if ($arg instanceof TokenInstance) {
                echo str_repeat('  ', $indent + 1) . $arg;
            } else {
                $arg->recursivePrint($indent + 1);
            }
        }
    }
}

class ElementDefinition {
    private $_name = null;
    private $_arity = null;
    private $_nested = null;
    private $_fixing = null;
    private $_priority = null;
    private $_tokenDefs = null;

    public function arity() { return $this->_arity; }
    public function nested() { return $this->_nested; }
    public function fixing() { return $this->_fixing; }
    public function priority() { return $this->_priority; }
    public function tokenDefs() { return $this->_tokenDefs; }
    public function name() { return $this->_name; }

    public function __construct($name, $fixing, $tokenDefs, $priority, $arity=null, $nested=null) {
        if ($tokenDefs instanceof TokenDefinition) {
            $tokenDefs = array($tokenDefs);
        }
        if (!is_array($tokenDefs)) {
            throw new InvalidArgumentException('tokenDefs can only be a TokenDefinition or an array of them.');
        }
        if ($arity === null) {
            switch ($fixing) {
                case Fixing::None:
                    $arity = 0;
                    break;
                case Fixing::Prefix:
                case Fixing::Postfix:
                    $arity = 1;
                    break;
                case Fixing::Infix:
                    $arity = -1;
                    break;
                case Fixing::Wrap:
                    break;
                default:
                    throw new InvalidArgumentException('Invalid fixing.');
                    break;
            }
        }
        $this->_name = $name;
        $this->_tokenDefs = $tokenDefs;
        $this->_arity = $arity;
        $this->_nested = $nested;
        $this->_fixing = $fixing;
        $this->_priority = $priority;

        switch ($this->fixing()) {
            case Fixing::None:
                if ($this->arity() != 0) {
                    throw new LogicException('An element with no fixing must be 0-ary.');
                }
                if (count($this->tokenDefs()) != 1) {
                    throw new LogicException('An element with no fixing must have exactly 1 token.');
                }
                break;
            case Fixing::Prefix:
                if ($this->arity() == 0) {
                    throw new LogicException('An prefix element must be n-ary with n > 0.');
                }
                if (count($this->tokenDefs()) != 1 && count($this->tokenDefs()) != $this->arity()) {
                    throw new LogicException('A n-ary prefix operator must have either 1 or n tokens.');
                }
                break;
            case Fixing::Postfix:
                if ($this->arity() == 0) {
                    throw new LogicException('An postfix element must be n-ary with n > 0.');
                }
                if (count($this->tokenDefs()) != 1 && count($this->tokenDefs()) != $this->arity()) {
                    throw new LogicException('A n-ary postfix operator must have either 1 or n tokens.');
                }
                break;
            case Fixing::Infix:
                if ($this->arity() == 0 || $this->arity() == 1) {
                    throw new LogicException('An infix element must be n-ary with n > 1.');
                }
                if (count($this->tokenDefs()) != 1 && count($this->tokenDefs()) != $this->arity() - 1) {
                    throw new LogicException('A n-ary infix operator must have either 1 or n-1 tokens.');
                }
                break;
            case Fixing::Wrap:
                if ($this->arity() !== null) {
                    throw new LogicException('Arity does not apply to a wrapping element.');
                }
                if (count($this->tokenDefs()) != 2) {
                    throw new LogicException('Wrapping operators are identified by exactly two tokens.');
                }
                break;
            default:
                throw new InvalidArgumentException('Invalid fixing specified.');
                break;
        }

        if ($this->fixing() == Fixing::Wrap) {
            if ($this->nested() === null) {
                throw new LogicException('You must specify whether a wrapping operator is nested.');
            }
        } else {
            if ($this->nested() !== null) {
                throw new LogicException('Nested applies only to wrapping operators.');
            }
        }
    }

    private static function _getLongestAlternateChain($args, $position, $tokDef, $stopAt=-1) {
        $nFound = 0;
        for ($lastFound = $position; $lastFound < count($args); $lastFound += 2) {
            if ($args[$lastFound]->definition() == $tokDef) {
                if ($stopAt >= 0 && $nFound >= $stopAt) {
                    break;
                }
                ++$nFound;
            } else {
                break;
            }
        }
        return $nFound;
    }

    private static function _isMatchingAlternateChain($args, $position, $tokDefs) {
        $tokDefIdx = 0;
        for ($lastFound = $position; $lastFound < count($args) && $tokDefIdx < count($tokDefs); $lastFound += 2) {
            if ($args[$lastFound]->definition() == $tokDefs[$tokDefIdx]) {
                ++$tokDefIdx;
            } else {
                return false;
            }
        }
        return ($tokDefIdx == count($tokDefs));
    }

    private static function _getWrappedSequence($args, $position, $tokDefs, $nested) {
        if (count($tokDefs) != 2) {
            throw new LogicException('Wrapping operators must have exactly 2 tokens.');
        }
        list($openTokDef, $closeTokDef) = $tokDefs;
        if ($args[$position]->definition() != $openTokDef) {
            return 0;
        }
        if ($nested) {
            // Get the longest sequence
            for ($i = count($args) - 1; $i > $position; --$i) {
                if ($args[$i]->definition() == $closeTokDef) {
                    return $i - $position + 1;
                }
            }
        } else {
            // Get the shortest sequence
            for ($i = $position + 1; $i < count($args); ++$i) {
                if ($args[$i]->definition() == $closeTokDef) {
                    return $i - $position + 1;
                }
            }
        }
        return 1;  // Which means unmatched sequence
    }

    private static function _extractAlternateChain($args, $position, $length) {
        $retval = array();
        for ($i = $position; $i < count($args) && count($retval) < $length; $i += 2) {
            $retval[] = $args[$i];
        }
        return $retval;
    }

    private static function _splicePrefix(&$args, $firstTokPosition, $chainLength, $definition) {
        if ($firstTokPosition < 0) {
            throw new InvalidArgumentException('Attempt to _splicePrefix with a negative offset.');
        }
        $elmArgs = self::_extractAlternateChain($args, $firstTokPosition + 1, $chainLength);
        $elmInst = new ElementInstance($definition, $elmArgs);
        if ($firstTokPosition + $chainLength * 2 > count($args)) {
            throw new NotEnoughArgumentsException($definition, $args[$firstTokPosition]);
        }
        array_splice($args, $firstTokPosition, $chainLength * 2, array($elmInst));
        return $firstTokPosition;
    }

    private static function _splicePostfix(&$args, $firstTokPosition, $chainLength, $definition) {
        if ($firstTokPosition < 0) {
            throw new InvalidArgumentException('Attempt to _splicePostfix with a negative offset.');
        }
        $elmArgs = self::_extractAlternateChain($args, $firstTokPosition - 1, $chainLength);
        $elmInst = new ElementInstance($definition, $elmArgs);
        if ($firstTokPosition == 0) {
            throw new NotEnoughArgumentsException($definition, $args[$firstTokPosition]);
        } elseif ($firstTokPosition + $chainLength * 2 - 1 > count($args)) {
            throw new NotEnoughArgumentsException($definition, $args[$firstTokPosition - 1]);
        }
        array_splice($args, $firstTokPosition - 1, $chainLength * 2, array($elmInst));
        return $firstTokPosition - 1;
    }

    private static function _spliceInfix(&$args, $firstTokPosition, $chainLength, $definition) {
        if ($firstTokPosition < 0) {
            throw new InvalidArgumentException('Attempt to _spliceInfix with a negative offset.');
        }
        $elmArgs = self::_extractAlternateChain($args, $firstTokPosition - 1, $chainLength + 1);
        $elmInst = new ElementInstance($definition, $elmArgs);
        if ($firstTokPosition == 0) {
            throw new NotEnoughArgumentsException($definition, $args[$firstTokPosition]);
        } elseif ($firstTokPosition + $chainLength * 2 > count($args)) {
            throw new NotEnoughArgumentsException($definition, $args[$firstTokPosition - 1]);
        }
        array_splice($args, $firstTokPosition - 1, $chainLength * 2 + 1, array($elmInst));
        return $firstTokPosition - 1;
    }

    private static function _spliceWrap(&$args, $firstTokPosition, $sequenceLength, $definition) {
        if ($firstTokPosition < 0) {
            throw new InvalidArgumentException('Attempt to _spliceWrap with a negative offset.');
        }
        if ($sequenceLength < 2) {
            throw new LogicException('A wrapping sequence must consist of at least the two wrapping tokens.');
        }
        if ($firstTokPosition + $sequenceLength > count($args)) {
            throw new LogicException('You requested to cut a sequence longer than the number of tokens.');
        }
        $elmArgs = array_slice($args, $firstTokPosition + 1, $sequenceLength - 2);
        $elmInst = new ElementInstance($definition, $elmArgs);
        array_splice($args, $firstTokPosition, $sequenceLength, array($elmInst));
        return $firstTokPosition;
    }

    private static function _spliceNone(&$args, $firstTokPosition, $definition) {
        if ($firstTokPosition < 0) {
            throw new InvalidArgumentException('Attempt to _spliceNone with a negative offset.');
        }
        if ($firstTokPosition + 1 > count($args)) {
            throw new LogicException('You requested to cut a token at the end of the tokens array.');
        }
        array_splice($args, $firstTokPosition, 1, array(new ElementInstance($definition, array($args[$firstTokPosition]))));
        return $firstTokPosition;
    }

    public function trySpliceAt(&$args, &$position) {
        switch ($this->fixing()) {
            case Fixing::None:
                if ($args[$position]->definition() == $this->tokenDefs()[0]) {
                    $position = self::_spliceNone($args, $position, $this);
                    return true;
                }
                break;
            case Fixing::Prefix:
                if ($this->arity() < 0) {
                    $chainLength = self::_getLongestAlternateChain($args, $position, $this->tokenDefs()[0]);
                    if ($chainLength > 0) {
                        $position = self::_splicePrefix($args, $position, $chainLength, $this);
                        return true;
                    }
                } else if (count($this->tokenDefs()) == 1) {
                    $chainLength = self::_getLongestAlternateChain($args, $position, $this->tokenDefs()[0], $this->arity());
                    if ($chainLength == $this->arity()) {
                        $position = self::_splicePrefix($args, $position, $chainLength, $this);
                        return true;
                    }
                } else if (self::_isMatchingAlternateChain($args, $position, $this->tokenDefs())) {
                    $position = self::_splicePrefix($args, $position, $this->arity(), $this);
                    return true;
                }
                break;
            case Fixing::Postfix:
                if ($this->arity() < 0) {
                    $chainLength = self::_getLongestAlternateChain($args, $position + 1, $this->tokenDefs()[0]);
                    if ($chainLength > 0) {
                        $position = self::_splicePostfix($args, $position + 1, $chainLength, $this);
                        return true;
                    }
                } else if (count($this->tokenDefs()) == 1) {
                    $chainLength = self::_getLongestAlternateChain($args, $position + 1, $this->tokenDefs()[0], $this->arity());
                    if ($chainLength == $this->arity()) {
                        $position = self::_splicePostfix($args, $position + 1, $chainLength, $this);
                        return true;
                    }
                } else if (self::_isMatchingAlternateChain($args, $position + 1, $this->tokenDefs())) {
                    $position = self::_splicePostfix($args, $position + 1, $this->arity(), $this);
                    return true;
                }
                break;
            case Fixing::Infix:
                if ($this->arity() < 0) {
                    $chainLength = self::_getLongestAlternateChain($args, $position + 1, $this->tokenDefs()[0]);
                    if ($chainLength > 0) {
                        $position = self::_spliceInfix($args, $position + 1, $chainLength, $this);
                        return true;
                    }
                } else if (count($this->tokenDefs()) == 1) {
                    $chainLength = self::_getLongestAlternateChain($args, $position + 1, $this->tokenDefs()[0], $this->arity());
                    if ($chainLength == $this->arity() - 1) {
                        $position = self::_spliceInfix($args, $position + 1, $chainLength, $this);
                        return true;
                    }
                } else if (self::_isMatchingAlternateChain($args, $position + 1, $this->tokenDefs())) {
                    $position = self::_spliceInfix($args, $position + 1, $this->arity() - 1, $this);
                    return true;
                }
                break;
            case Fixing::Wrap:
                $sequenceLength = self::_getWrappedSequence($args, $position, $this->tokenDefs(), $this->nested());
                if ($sequenceLength >= 2) {
                    $position = self::_spliceWrap($args, $position, $sequenceLength, $this);
                    return true;
                } elseif ($sequenceLength == 1) {
                    throw new UnmatchedWrapperException($this, $args[$position]);
                }
                break;
        }
    }

    public function spliceInstancesIn(&$args) {
        $somethingHappened = false;
        for ($i = 0; $i < count($args); ++$i) {
            if ($this->trySpliceAt($args, $i)) {
                $somethingHappened = true;
            }
        }
        return $somethingHappened;
    }

}

$T_AT = new TokenDefinition('@', 'AT');
$T_EXCL = new TokenDefinition('!', 'EXCL');
$T_AND = new TokenDefinition('&&', 'AND');
$T_OR = new TokenDefinition('||', 'OR');
$T_OPEN_PAREN = new TokenDefinition('(', 'OPENP');
$T_CLOSE_PAREN = new TokenDefinition(')', 'CLOSEP');
$T_LITERAL = new TokenDefinition(null, 'LIT', '/\w+/');
$T_SPACE = new TokenDefinition(' ', 'SPC', '/\s+/');

$ALL_TOKENS = array($T_AT, $T_EXCL, $T_AND, $T_OR, $T_OPEN_PAREN, $T_CLOSE_PAREN, $T_LITERAL, $T_SPACE);
$IGNORE_TOKENS = array($T_SPACE);

$ELM_LITERAL = new ElementDefinition('Literal', Fixing::None, $T_LITERAL, 0);
$ELM_SUBEXPR = new ElementDefinition('Subexpr', Fixing::Wrap, array($T_OPEN_PAREN, $T_CLOSE_PAREN), 1, null, true);
$ELM_GROUP = new ElementDefinition('InGroup', Fixing::Prefix, $T_AT, 2);
$ELM_NEG = new ElementDefinition('Not', Fixing::Prefix, $T_EXCL, 3);
$ELM_AND = new ElementDefinition('And', Fixing::Infix, $T_AND, 4);
$ELM_OR = new ElementDefinition('Or', Fixing::Infix, $T_OR, 5);

$ALL_ELEMENTS = array($ELM_LITERAL, $ELM_SUBEXPR, $ELM_GROUP, $ELM_NEG, $ELM_AND, $ELM_OR);

function tokenize(string $text, array $tokDefs, array $stripTokDefs) {
    $tokInsts = array();
    $foundTokInst = null;
    for ($position = 0; $position < strlen($text); $position += $foundTokInst->length()) {
        $foundTokInst = null;
        foreach ($tokDefs as $tokDef) {
            $match = $tokDef->tryMatch($text, $position);
            if ($match !== null) {
                $foundTokInst = new TokenInstance($tokDef, $text, $position, strlen($match));
                break;
            }
        }
        if ($foundTokInst === null) {
            throw new UnknownTokenException($text, $position);
        } elseif (!in_array($foundTokInst->definition(), $stripTokDefs)) {
            $tokInsts[] = $foundTokInst;
        }
    }
    return $tokInsts;
}

function parse(array $tokInsts, array $elmDefs) {
    usort($elmDefs, function ($a, $b) { return $a->priority() - $b->priority(); });
    $root = new ElementInstance(null, $tokInsts);
    foreach ($elmDefs as $elmDef) {
        $root->recursiveExpand($elmDef);
    }
    if (!$root->isFullyExpanded()) {
        throw new StrayTokenException($root->recursiveFindUnexpandedToken());
    }
    return $root;
}

$text = 'usr1 ||(!usr2&&@group || !usr3)';
echo 'Parsing "' . $text . '".' . "\n";
parse(tokenize($text, $ALL_TOKENS, $IGNORE_TOKENS), $ALL_ELEMENTS)->recursivePrint();

?>