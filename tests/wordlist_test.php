<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for the word list parser.
 *
 * @package    filter_ruby
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby;

use filter_ruby\local\wordlist;

/**
 * Unit tests for {@see \filter_ruby\local\wordlist}.
 *
 * @package    filter_ruby
 * @category   test
 * @covers     \filter_ruby\local\wordlist
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wordlist_test extends \advanced_testcase {
    /**
     * The parser is pure and writes nothing, but resetting keeps the case safe
     * should a future assertion reach for configuration or the database.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A raw list parses into the expected word to reading map.
     *
     * assertSame is deliberate: key order and the string type of an empty
     * reading both matter to callers.
     *
     * @param string $raw The raw textarea contents.
     * @param array $expected The expected word to reading map.
     * @return void
     * @dataProvider parse_provider
     */
    public function test_parse(string $raw, array $expected): void {
        $this->assertSame($expected, wordlist::parse($raw));
    }

    /**
     * Data provider for {@see self::test_parse()}.
     *
     * @return array Cases of [raw list, expected map].
     */
    public static function parse_provider(): array {
        return [
            'empty string' => ['', []],
            'whitespace only' => ["   \n\t\n  ", []],
            'blank lines are skipped' => ["\n\n漢字=かんじ\n\n", ['漢字' => 'かんじ']],
            'comment lines are skipped' => [
                "# a header comment\n    # an indented comment\n漢字=かんじ",
                ['漢字' => 'かんじ'],
            ],
            'a comment only list yields nothing' => ["# nothing here\n# nor here", []],
            'a hash after the start is not a comment' => [
                '漢字=かんじ # not a trailing comment',
                ['漢字' => 'かんじ # not a trailing comment'],
            ],
            'a single entry' => ['漢字=かんじ', ['漢字' => 'かんじ']],
            'whitespace around word and reading is trimmed' => [
                "  \t漢字 \t=  かんじ  \t",
                ['漢字' => 'かんじ'],
            ],
            'the full-width separator is accepted' => ['漢字＝かんじ', ['漢字' => 'かんじ']],
            'crlf line endings' => [
                "漢字=かんじ\r\n今日=きょう\r\n",
                ['漢字' => 'かんじ', '今日' => 'きょう'],
            ],
            'lone cr line endings' => [
                "漢字=かんじ\r今日=きょう",
                ['漢字' => 'かんじ', '今日' => 'きょう'],
            ],
            'mixed line endings' => [
                "漢字=かんじ\r\n今日=きょう\r勉強=べんきょう\n",
                ['漢字' => 'かんじ', '今日' => 'きょう', '勉強' => 'べんきょう'],
            ],
            'an empty reading survives as an empty string' => ['漢字=', ['漢字' => '']],
            'an empty reading with the full-width separator' => ['漢字＝', ['漢字' => '']],
            'an empty reading padded with spaces' => ["漢字=   \n", ['漢字' => '']],
            'an empty word is dropped' => ['=かんじ', []],
            'an empty word after trimming is dropped' => ['   =かんじ', []],
            'a bare separator is dropped' => ['=', []],
            'a line with no separator is dropped' => ['漢字', []],
            'a malformed line does not stop the good ones' => [
                "漢字\n今日=きょう\n=かんじ\n勉強=べんきょう",
                ['今日' => 'きょう', '勉強' => 'べんきょう'],
            ],
            'a later line overwrites an earlier one' => [
                "金=きん\n金=かね",
                ['金' => 'かね'],
            ],
            'a later empty reading overwrites a real one' => [
                "金=きん\n金=",
                ['金' => ''],
            ],
            'only the first separator splits the line' => ['a=b=c', ['a' => 'b=c']],
            'the first separator wins when the full-width one comes first' => [
                '漢字＝かんじ=x',
                ['漢字' => 'かんじ=x'],
            ],
            'the first separator wins when the ascii one comes first' => [
                'a=b＝c',
                ['a' => 'b＝c'],
            ],
            'a realistic list' => [
                "# Week 3 vocabulary\n\n今日=きょう\r\n漢字＝かんじ\n難しい=むずかしい\n"
                    . "金=  \n  broken line  \n今日=こんにち\n",
                ['今日' => 'こんにち', '漢字' => 'かんじ', '難しい' => 'むずかしい', '金' => ''],
            ],
            'a word at the length limit is kept' => [
                str_repeat('漢', 32) . '=かん',
                [str_repeat('漢', 32) => 'かん'],
            ],
            'a word over the length limit is dropped, the rest of the list is kept' => [
                str_repeat('漢', 33) . "=かん\n今日=きょう",
                ['今日' => 'きょう'],
            ],
        ];
    }

    /**
     * A raw list reports exactly the expected diagnostics.
     *
     * @param string $raw The raw textarea contents.
     * @param array $expected The expected list of diagnostics.
     * @return void
     * @dataProvider check_provider
     */
    public function test_check(string $raw, array $expected): void {
        $this->assertSame($expected, wordlist::check($raw));
    }

    /**
     * Data provider for {@see self::test_check()}.
     *
     * @return array Cases of [raw list, expected diagnostics].
     */
    public static function check_provider(): array {
        return [
            'empty string' => ['', []],
            'whitespace only' => ["  \n\t\n", []],
            'a clean list has no problems' => ["今日=きょう\n漢字=かんじ", []],
            'comments and blank lines are not problems' => ["# comment\n\n   # indented\n", []],
            'a hiragana reading is fine' => ['漢字=かんじ', []],
            'a katakana reading with a prolonged sound mark is fine' => ['珈琲=コーヒー', []],
            'a reading with a middle dot is fine' => ['山田太郎=やまだ・たろう', []],
            'a reading with a space is fine' => ['山田太郎=やまだ たろう', []],
            'an empty reading is the suppress form and is not a problem' => ['漢字=', []],
            'an empty reading with the full-width separator is not a problem' => ['漢字＝', []],
            'a line with no separator' => [
                '漢字',
                [['line' => 1, 'text' => '漢字', 'problem' => 'noseparator']],
            ],
            'the reported text is the trimmed line' => [
                "   漢字だけ   \n",
                [['line' => 1, 'text' => '漢字だけ', 'problem' => 'noseparator']],
            ],
            'an empty word' => [
                '=かんじ',
                [['line' => 1, 'text' => '=かんじ', 'problem' => 'emptyword']],
            ],
            'an empty word after trimming' => [
                '   =かんじ',
                [['line' => 1, 'text' => '=かんじ', 'problem' => 'emptyword']],
            ],
            'an empty word with the full-width separator' => [
                '＝かんじ',
                [['line' => 1, 'text' => '＝かんじ', 'problem' => 'emptyword']],
            ],
            'a bare separator is an empty word, not an empty reading' => [
                '=',
                [['line' => 1, 'text' => '=', 'problem' => 'emptyword']],
            ],
            'a romaji reading is not kana' => [
                '漢字=kanji',
                [['line' => 1, 'text' => '漢字=kanji', 'problem' => 'notkana']],
            ],
            'a reading containing kanji is not kana' => [
                '今日=今日',
                [['line' => 1, 'text' => '今日=今日', 'problem' => 'notkana']],
            ],
            'a reading holding a second separator is not kana' => [
                'a=b=c',
                [['line' => 1, 'text' => 'a=b=c', 'problem' => 'notkana']],
            ],
            'line numbers count blank and comment lines' => [
                "# comment\n\n漢字\n",
                [['line' => 3, 'text' => '漢字', 'problem' => 'noseparator']],
            ],
            'line numbers survive crlf' => [
                "# comment\r\n漢字\r\n=かんじ\r\n",
                [
                    ['line' => 2, 'text' => '漢字', 'problem' => 'noseparator'],
                    ['line' => 3, 'text' => '=かんじ', 'problem' => 'emptyword'],
                ],
            ],
            'line numbers survive a lone cr' => [
                "今日=きょう\r漢字\r=かんじ",
                [
                    ['line' => 2, 'text' => '漢字', 'problem' => 'noseparator'],
                    ['line' => 3, 'text' => '=かんじ', 'problem' => 'emptyword'],
                ],
            ],
            'duplicate words are not a problem' => ["金=きん\n金=かね", []],
            'every problem is reported, in line order' => [
                "今日=きょう\n漢字\n=かんじ\n勉強=benkyou\n難しい=",
                [
                    ['line' => 2, 'text' => '漢字', 'problem' => 'noseparator'],
                    ['line' => 3, 'text' => '=かんじ', 'problem' => 'emptyword'],
                    ['line' => 4, 'text' => '勉強=benkyou', 'problem' => 'notkana'],
                ],
            ],
            'a word at the length limit is not a problem' => [str_repeat('漢', 32) . '=かん', []],
            'a word over the length limit is too long' => [
                "今日=きょう\n" . str_repeat('漢', 33) . '=かん',
                [['line' => 2, 'text' => str_repeat('漢', 33) . '=かん', 'problem' => 'toolong']],
            ],
        ];
    }

    /**
     * Every problem class is reported by check() while parse() stays non-fatal.
     *
     * The two methods must agree: the errors drop entries, the notkana warning
     * does not, and the suppression entry survives untouched.
     *
     * @return void
     */
    public function test_parse_and_check_agree_on_a_messy_list(): void {
        $raw = "# Messy list\r\n今日=きょう\r\n漢字\r=かんじ\n勉強=benkyou\n難しい=\n今日=こんにち\n";

        $this->assertSame(
            [
                '今日' => 'こんにち',
                '勉強' => 'benkyou',
                '難しい' => '',
            ],
            wordlist::parse($raw)
        );

        $this->assertSame(
            [
                ['line' => 3, 'text' => '漢字', 'problem' => 'noseparator'],
                ['line' => 4, 'text' => '=かんじ', 'problem' => 'emptyword'],
                ['line' => 5, 'text' => '勉強=benkyou', 'problem' => 'notkana'],
            ],
            wordlist::check($raw)
        );
    }
}
