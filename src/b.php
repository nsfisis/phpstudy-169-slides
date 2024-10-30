<?php

declare(strict_types=1);

enum Word {
    case PhpTag;
    case LessThan;
    case LeftParen;
    case RightParen;
    case LeftBrace;
    case RightBrace;
    case Semicolon;
    case Modulo;
    case Increment;
    case StrictlyEqual;
    case Assign;
    case Echo_;
    case For_;
    case If_;
    case ElseIf_;
    case Else_;
}

readonly class Variable {
    public function __construct(
        public string $name,
    ) {}
}

function split_into_words(string $input): array {
    $i = 0;
    $result = [];

    while ($i < strlen($input)) {
        $first = $input[$i];
        if ($first === '<') {
            $second = $input[$i + 1];
            if ($second === '=') {
                $result[] = Word::LessThan;
                $i += 2;
            } else {
                $result[] = Word::PhpTag;
                $i += 5;
            }
        } else if ($first === '(') {
            $result[] = Word::LeftParen;
            $i += 1;
        } else if ($first === ')') {
            $result[] = Word::RightParen;
            $i += 1;
        } else if ($first === '{') {
            $result[] = Word::LeftBrace;
            $i += 1;
        } else if ($first === '}') {
            $result[] = Word::RightBrace;
            $i += 1;
        } else if ($first === ';') {
            $result[] = Word::Semicolon;
            $i += 1;
        } else if ($first === '%') {
            $result[] = Word::Modulo;
            $i += 1;
        } else if ($first === '+') {
            $result[] = Word::Increment;
            $i += 2;
        } else if ($first === '=') {
            $second = $input[$i + 1];
            if ($second === '=') {
                $result[] = Word::StrictlyEqual;
                $i += 3;
            } else {
                $result[] = Word::Assign;
                $i += 1;
            }
        } else if (ctype_space($first)) {
            $i += 1;
        } else if (ctype_digit($first)) {
            $j = $i;
            while (ctype_digit($input[$j])) {
                $j += 1;
            }
            $result[] = (int) substr($input, $i, $j - $i);
            $i = $j;
        } else if (ctype_alpha($first)) {
            $j = $i;
            while (ctype_alpha($input[$j])) {
                $j += 1;
            }
            $result[] = match (substr($input, $i, $j - $i)) {
                'echo' => Word::Echo_,
                'for' => Word::For_,
                'if' => Word::If_,
                'elseif' => Word::ElseIf_,
                'else' => Word::Else_,
            };
            $i = $j;
        } else if ($first === '$') {
            $i += 1;
            $j = $i;
            while (ctype_alpha($input[$j])) {
                $j += 1;
            }
            $result[] = new Variable(substr($input, $i, $j - $i));
            $i = $j;
        } else if ($first === '"') {
            $i += 1;
            $j = $i;
            $s = '';
            while ($input[$j] !== '"') {
                if ($input[$j] === '\\') {
                    $j += 1;
                    if ($input[$j] === 'n') {
                        $s .= "\n";
                    } else {
                        $s .= $input[$j];
                    }
                } else {
                    $s .= $input[$j];
                }
                $j += 1;
            }
            $result[] = $s;
            $i = $j + 1;
        }
    }

    return $result;
}

class Php {
    private array $words;
    private int $position;
    private array $variables;

    public function __construct(array $words) {
        $this->words = $words;
        $this->position = 0;
        $this->variables = [];
    }

    public function runPhp(): void {
        $this->expectWord(Word::PhpTag);
        $this->runStatements();
    }

    private function runStatements(bool $doRun = true): void {
        while (true) {
            $first = $this->words[$this->position] ?? null;
            if ($first === Word::For_) {
                $this->runForStatement($doRun);
            } else if ($first === Word::If_) {
                $this->runIfStatement($doRun);
            } else if ($first === Word::Echo_) {
                $this->runEchoStatement($doRun);
            } else {
                break;
            }
        }
    }

    private function runForStatement(bool $doRun = true): void {
        $this->position += 1; // skip 'for'
        $this->expectWord(Word::LeftParen);
        $this->calculateExpression($doRun);
        $this->expectWord(Word::Semicolon);
        $condition_position = $this->position;
        while (true) {
            $condition_result = $this->calculateExpression($doRun);
            $this->expectWord(Word::Semicolon);
            $update_position = $this->position;
            if (!$condition_result) {
                $this->skipExpression();
                $this->expectWord(Word::RightParen);
                $this->expectWord(Word::LeftBrace);
                $this->skipProgram();
                $this->expectWord(Word::RightBrace);
                break;
            }
            $this->skipExpression();
            $this->expectWord(Word::RightParen);
            $this->expectWord(Word::LeftBrace);
            $this->runStatements($doRun);
            $this->expectWord(Word::RightBrace);
            $this->position = $update_position;
            $this->calculateExpression($doRun);
            $this->position = $condition_position;
        }
    }

    private function runIfStatement(bool $doRun = true): void {
        $this->position += 1; // skip 'if' or 'elseif'
        $this->expectWord(Word::LeftParen);
        $condition = $this->calculateExpression($doRun);
        $this->expectWord(Word::RightParen);
        $this->expectWord(Word::LeftBrace);
        $this->runStatements($doRun && $condition);
        $this->expectWord(Word::RightBrace);
        $next_word = $this->words[$this->position] ?? null;
        if ($next_word === Word::ElseIf_) {
            $this->runIfStatement($doRun && !$condition);
        } else if ($next_word === Word::Else_) {
            $this->position += 1; // skip 'else'
            $this->expectWord(Word::LeftBrace);
            $this->runStatements($doRun && !$condition);
            $this->expectWord(Word::RightBrace);
        }
    }

    private function runEchoStatement(bool $doRun = true): void {
        $this->position += 1; // skip 'echo'
        $value = $this->calculateExpression($doRun);
        if ($doRun) {
            echo $value;
        }
        $this->expectWord(Word::Semicolon);
    }

    private function calculateExpression(bool $doRun = true): int|string|bool|null {
        $left_hand_side = $this->getNextWord();
        while (true) {
            $next_word = $this->words[$this->position];
            if ($next_word === Word::Assign) {
                $this->position += 1; // skip '='
                $right_hand_side = $this->getNextWord();
                if ($doRun) {
                    assert($left_hand_side instanceof Variable);
                    $left_hand_side = $this->variables[$left_hand_side->name] = $right_hand_side;
                }
            } else if ($next_word === Word::LessThan) {
                $this->position += 1; // skip '<='
                $right_hand_side = $this->getNextWord();
                $left_hand_side = $this->convertWordToValue($left_hand_side) <= $this->convertWordToValue($right_hand_side);
            } else if ($next_word === Word::StrictlyEqual) {
                $this->position += 1; // skip '==='
                $right_hand_side = $this->getNextWord();
                $left_hand_side = $this->convertWordToValue($left_hand_side) === $this->convertWordToValue($right_hand_side);
            } else if ($next_word === Word::Modulo) {
                $this->position += 1; // skip '%'
                $right_hand_side = $this->getNextWord();
                $left_hand_side = $this->convertWordToValue($left_hand_side) % $this->convertWordToValue($right_hand_side);
            } else if ($next_word === Word::Increment) {
                $this->position += 1; // skip '++'
                if ($doRun) {
                    $left_hand_side = $this->variables[$left_hand_side->name] = $this->variables[$left_hand_side->name] + 1;
                }
            } else {
                return $this->convertWordToValue($left_hand_side);
            }
        }
    }

    private function getNextWord(): int|string|Variable {
        $word = $this->words[$this->position];
        $this->position += 1;
        return $word;
    }

    private function convertWordToValue(int|string|bool|Variable $word): int|string|bool|null {
        if ($word instanceof Variable) {
            return $this->variables[$word->name];
        } else {
            return $word;
        }
    }

    private function skipExpression(): void {
        $this->calculateExpression(doRun: false);
    }

    private function skipProgram(): void {
        $this->runStatements(doRun: false);
    }

    private function expectWord(Word $expected_word): void {
        if ($this->words[$this->position] !== $expected_word) {
            throw new RuntimeException("Expected $expected_word at position $this->position");
        }
        $this->position += 1;
    }
}

$input = file_get_contents('./a.php');
$words = split_into_words($input);

$php = new Php($words);
$php->runPhp();
