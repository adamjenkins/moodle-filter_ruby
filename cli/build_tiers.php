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
 * Developer tool that generates the shipped tier dictionaries in data/.
 *
 * This is NOT runtime code and is never loaded by the filter. It streams JMdict
 * (the English-only JMdict_e edition) and KANJIDIC2 with XMLReader and writes
 * data/tier_elementary.php, data/tier_jhs.php, data/tier_shs.php and
 * data/tier_university.php. Running it twice on the same sources produces
 * byte-identical files.
 *
 * Usage:
 *     php cli/build_tiers.php --jmdict=JMdict_e.gz --kanjidic=kanjidic2.xml.gz \
 *         [--outdir=DIR] [--maxwords=N] [--commonband=N] [--quiet]
 *
 * Sources (both gzipped or plain XML, decided by the .gz extension):
 *     http://ftp.edrdg.org/pub/Nihongo/JMdict_e.gz
 *     http://www.edrdg.org/kanjidic/kanjidic2.xml.gz
 *
 * JMdict and KANJIDIC2 are the property of the Electronic Dictionary Research
 * and Development Group and are used under the Creative Commons Attribution
 * ShareAlike 4.0 licence. See thirdpartylibs.xml.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This tool deliberately does not bootstrap Moodle: it has to run from a bare
// plugin checkout so that generated files land in the repository, and on a
// typical server $CFG->dataroot is not readable by the developer account.
// Defining the guard constant keeps the file compliant with the Moodle coding
// standard; actual protection against web access is the CLI check below.
define('MOODLE_INTERNAL', true);
defined('MOODLE_INTERNAL') || die();

if (PHP_SAPI !== 'cli') {
    die('build_tiers.php is a developer tool and can only be run from the command line.');
}

filter_ruby_build_tiers(filter_ruby_options($argv));

/**
 * Parse the command line.
 *
 * @param array $argv Raw argument vector.
 * @return array Option name => value.
 */
function filter_ruby_options(array $argv): array {
    $defaults = [
        'jmdict' => getcwd() . '/JMdict_e.gz',
        'kanjidic' => getcwd() . '/kanjidic2.xml.gz',
        'outdir' => dirname(__DIR__) . '/data',
        'maxwords' => 4500,
        'commonband' => 12,
    ];
    $usage = 'Usage: php cli/build_tiers.php [--jmdict=PATH] [--kanjidic=PATH] [--outdir=DIR]'
        . ' [--maxwords=N] [--commonband=N] [--quiet]';
    $options = $defaults + ['quiet' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--quiet') {
            $options['quiet'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            echo $usage . PHP_EOL;
            exit(0);
        }
        if (!preg_match('/^--([a-z]+)=(.*)$/', $arg, $matches) || !array_key_exists($matches[1], $defaults)) {
            filter_ruby_fail("Unknown or malformed option: {$arg}" . PHP_EOL . $usage);
        }
        $options[$matches[1]] = is_int($defaults[$matches[1]]) ? (int) $matches[2] : $matches[2];
    }
    if ($options['maxwords'] < 1 || $options['commonband'] < 1 || $options['commonband'] > 48) {
        filter_ruby_fail('--maxwords must be positive and --commonband must be between 1 and 48.');
    }
    return $options;
}

/**
 * Print a message to stderr and stop.
 *
 * @param string $message Reason for stopping.
 * @return void
 */
function filter_ruby_fail(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * Open an XMLReader on a plain or gzipped XML file.
 *
 * @param string $path Path to the source file.
 * @return XMLReader Positioned before the first node.
 */
function filter_ruby_reader(string $path): XMLReader {
    if (!is_readable($path)) {
        filter_ruby_fail("Cannot read source file: {$path}");
    }
    $uri = str_ends_with($path, '.gz') ? 'compress.zlib://' . $path : $path;
    $reader = new XMLReader();
    if (!@$reader->open($uri, 'UTF-8', LIBXML_NONET)) {
        filter_ruby_fail("Cannot parse source file: {$path}");
    }
    return $reader;
}

/**
 * Collect the text content of every occurrence of one element in a fragment.
 *
 * Entity references are left unexpanded, so an element such as ke_inf yields
 * strings like '&rK;'.
 *
 * @param string $xml An XML fragment.
 * @param string $tag Element name.
 * @return array List of raw inner strings.
 */
function filter_ruby_values(string $xml, string $tag): array {
    if (!preg_match_all('#<' . $tag . '>(.*?)</' . $tag . '>#s', $xml, $matches)) {
        return [];
    }
    return $matches[1];
}

/**
 * Reduce JMdict entity references to their bare codes.
 *
 * @param array $values Strings such as '&rK;'.
 * @return array Codes such as 'rK'.
 */
function filter_ruby_codes(array $values): array {
    $codes = [];
    foreach ($values as $value) {
        if (preg_match('/^&([a-zA-Z0-9-]+);$/', trim($value), $matches)) {
            $codes[] = $matches[1];
        }
    }
    return $codes;
}

/**
 * Stream KANJIDIC2 and index every kanji by grade, frequency rank and reading.
 *
 * @param string $path Path to kanjidic2.xml or kanjidic2.xml.gz.
 * @return array Keys 'grade', 'freq', 'reading', 'version', 'date'.
 */
function filter_ruby_load_kanjidic(string $path): array {
    $reader = filter_ruby_reader($path);
    $index = ['grade' => [], 'freq' => [], 'reading' => [], 'version' => '', 'date' => ''];
    while (@$reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        if ($reader->name === 'header') {
            $header = $reader->readOuterXml();
            $index['version'] = filter_ruby_values($header, 'database_version')[0] ?? '';
            $index['date'] = filter_ruby_values($header, 'date_of_creation')[0] ?? '';
            continue;
        }
        if ($reader->name !== 'character') {
            continue;
        }
        $xml = $reader->readOuterXml();
        $literal = filter_ruby_values($xml, 'literal')[0] ?? '';
        if ($literal === '') {
            continue;
        }
        $grade = filter_ruby_values($xml, 'grade');
        $freq = filter_ruby_values($xml, 'freq');
        $index['grade'][$literal] = $grade === [] ? null : (int) $grade[0];
        $index['freq'][$literal] = $freq === [] ? null : (int) $freq[0];
        $reading = filter_ruby_kanji_reading($xml);
        if ($reading !== null) {
            $index['reading'][$literal] = $reading;
        }
    }
    $reader->close();
    return $index;
}

/**
 * Choose the single fallback reading for one kanji.
 *
 * A kun'yomi that needs no okurigana (yama for the mountain kanji) is the
 * reading the character actually has on its own, so it is preferred. When every
 * kun'yomi is bound to okurigana ('mudzuka.shii') or to a compound position
 * (marked with a hyphen) the bare character is far more often a Sino-Japanese
 * noun, so the first on'yomi is used instead, converted from katakana to
 * hiragana because furigana is conventionally hiragana. A truncated kun'yomi is
 * the next resort, for the handful of kanji with no on'yomi at all.
 *
 * Each of those steps insists on hiragana, which skips the katakana kun'yomi
 * KANJIDIC2 records for loanword ateji, so the coral kanji takes its on'yomi
 * 'san' rather than the 'senchi' it only has as an abbreviation. A katakana
 * kun'yomi is accepted last of all, for unit kanji that have no other reading.
 *
 * @param string $xml One character element from KANJIDIC2.
 * @return string|null Hiragana reading, or null when the kanji has none.
 */
function filter_ruby_kanji_reading(string $xml): ?string {
    $group = filter_ruby_values($xml, 'rmgroup')[0] ?? $xml;
    preg_match_all('#<reading r_type="ja_(on|kun)">(.*?)</reading>#s', $group, $matches, PREG_SET_ORDER);
    $on = [];
    $kun = [];
    foreach ($matches as $match) {
        if ($match[1] === 'on') {
            $on[] = $match[2];
        } else {
            $kun[] = $match[2];
        }
    }
    foreach ($kun as $reading) {
        if (!str_contains($reading, '.') && !str_contains($reading, '-') && filter_ruby_is_hiragana($reading)) {
            return $reading;
        }
    }
    foreach ($on as $reading) {
        $reading = mb_convert_kana(trim(explode('.', $reading)[0], '-'), 'c', 'UTF-8');
        if (filter_ruby_is_hiragana($reading)) {
            return $reading;
        }
    }
    foreach ($kun as $reading) {
        $reading = trim(explode('.', $reading)[0], '-');
        if (filter_ruby_is_hiragana($reading)) {
            return $reading;
        }
    }
    foreach ($kun as $reading) {
        if (!str_contains($reading, '.') && !str_contains($reading, '-') && filter_ruby_is_kana($reading)) {
            return $reading;
        }
    }
    return null;
}

/**
 * Is this string made only of hiragana?
 *
 * @param string $text Candidate reading.
 * @return bool True when the string is non-empty hiragana.
 */
function filter_ruby_is_hiragana(string $text): bool {
    return (bool) preg_match('/^\p{Hiragana}+$/u', $text);
}

/**
 * Is this string made only of kana?
 *
 * @param string $text Candidate reading.
 * @return bool True when the string is non-empty kana.
 */
function filter_ruby_is_kana(string $text): bool {
    return (bool) preg_match('/^[\p{Hiragana}\p{Katakana}ー]+$/u', $text);
}

/**
 * Stream JMdict and collect every usable kanji writing with its reading.
 *
 * A writing is usable when it is the standard form of the word (irregular,
 * outdated, rare and search-only forms are dropped), is written only in kanji
 * and kana, starts with a kanji, is at most eight characters long, is in
 * normalised form and carries a frequency tag. Starting with a kanji matters
 * because the runtime scanner only attempts matches at kanji positions, so a
 * writing beginning with kana could never be found.
 *
 * Two further indexes are built from the same pass, and they deliberately see
 * more writings than the word list does: they exist to say when a kanji run is
 * ambiguous, and evidence of ambiguity must not be thrown away just because the
 * writing carrying it is too long to be a word entry itself.
 *
 * - 'families' maps a kanji run to the set of okurigana reading stems the
 *   corpus measures for it. It is the evidence for {@see filter_ruby_word_map()}
 *   deciding whether a run key has one dominant reading.
 * - 'headwords' maps a kanji-only writing to the set of readings the corpus
 *   measures for it as a word in its own right, at any length. A run key is
 *   matched wherever the run occurs, including standing alone, so a run whose
 *   characters are themselves a measured word read differently is unsafe.
 *
 * Both indexes hold only writings that carry a frequency tag. A writing with no
 * tag is not evidence of anything: the corpus never measured it, and the word
 * list itself keeps nothing untagged either.
 *
 * @param string $path Path to JMdict_e or JMdict_e.gz.
 * @param array $index KANJIDIC2 index from filter_ruby_load_kanjidic().
 * @return array Keys 'words' (list of word records), 'families' and 'headwords'.
 */
function filter_ruby_load_jmdict(string $path, array $index): array {
    $reader = filter_ruby_reader($path);
    $skipforms = ['sK', 'rK', 'iK', 'ik', 'oK', 'io'];
    $skipreadings = ['sk', 'ok', 'ik', 'rk'];
    $best = [];
    $families = [];
    $headwords = [];
    while (@$reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'entry') {
            continue;
        }
        $xml = $reader->readOuterXml();
        $seq = (int) (filter_ruby_values($xml, 'ent_seq')[0] ?? 0);
        $inflects = false;
        foreach (filter_ruby_codes(filter_ruby_values($xml, 'pos')) as $pos) {
            $inflects = $inflects || (bool) preg_match('/^(v[1245]|vk|vn|vr|vz|adj-i)/', $pos);
        }
        $readings = [];
        foreach (filter_ruby_values($xml, 'r_ele') as $block) {
            if (str_contains($block, '<re_nokanji/>') || str_contains($block, '<re_nokanji />')) {
                continue;
            }
            if (array_intersect(filter_ruby_codes(filter_ruby_values($block, 're_inf')), $skipreadings) !== []) {
                continue;
            }
            $readings[] = [
                'reb' => filter_ruby_values($block, 'reb')[0] ?? '',
                'restr' => filter_ruby_values($block, 're_restr'),
            ];
        }
        foreach (filter_ruby_values($xml, 'k_ele') as $block) {
            $keb = filter_ruby_values($block, 'keb')[0] ?? '';
            if ($keb === '') {
                continue;
            }
            if (array_intersect(filter_ruby_codes(filter_ruby_values($block, 'ke_inf')), $skipforms) !== []) {
                continue;
            }
            $score = filter_ruby_band(filter_ruby_values($block, 'ke_pri'));
            $reading = $score === null ? null : filter_ruby_reading_for($keb, $readings);
            if ($reading !== null && filter_ruby_is_normalised($keb) && filter_ruby_is_normalised($reading)) {
                if (preg_match('/^\p{Han}+$/u', $keb)) {
                    $headwords[$keb][$reading] = true;
                } else if (preg_match('/^(\p{Han}+)(\p{Hiragana}+)$/u', $keb, $matches)) {
                    $stem = str_ends_with($reading, $matches[2])
                        ? mb_substr($reading, 0, mb_strlen($reading) - mb_strlen($matches[2]))
                        : '';
                    if ($stem !== '' && filter_ruby_is_kana($stem)) {
                        $families[$matches[1]][$stem] = true;
                    }
                }
            }
            if (!preg_match('/^\p{Han}[\p{Han}\p{Hiragana}\p{Katakana}ー]*$/u', $keb) || mb_strlen($keb) > 8) {
                continue;
            }
            if ($reading === null || !filter_ruby_is_normalised($keb) || !filter_ruby_is_normalised($reading)) {
                continue;
            }
            $current = $best[$keb] ?? null;
            if ($current !== null && [$current['rank'], $current['seq']] <= [$score['rank'], $seq]) {
                continue;
            }
            $best[$keb] = [
                'keb' => $keb,
                'reading' => $reading,
                'band' => $score['band'],
                'rank' => $score['rank'],
                'seq' => $seq,
                'inflects' => $inflects,
                'hardness' => filter_ruby_hardness($keb, $index['grade']),
            ];
        }
    }
    $reader->close();
    return ['words' => array_values($best), 'families' => $families, 'headwords' => $headwords];
}

/**
 * Is this string safe to ship as a dictionary key or reading?
 *
 * Two things disqualify it. The CJK compatibility ideograph blocks are variant
 * codepoints that a browser, an editor and every input method fold away, so a
 * key containing one could never match text a user actually typed while being a
 * duplicate of a character the data already holds. And any string that is not
 * already in Unicode normalisation form C would likewise never match: Moodle
 * stores what the browser submits, and browsers submit NFC.
 *
 * The block test is not redundant with the normalisation test. Twelve of the
 * compatibility ideographs have no canonical decomposition and so are NFC
 * stable, and they are excluded on the same reasoning as the rest.
 *
 * @param string $text A candidate key or reading.
 * @return bool True when the string may be shipped.
 */
function filter_ruby_is_normalised(string $text): bool {
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $character) {
        $codepoint = mb_ord($character, 'UTF-8');
        if (($codepoint >= 0xF900 && $codepoint <= 0xFAFF) || ($codepoint >= 0x2F800 && $codepoint <= 0x2FA1F)) {
            return false;
        }
    }
    return Normalizer::isNormalized($text, Normalizer::FORM_C);
}

/**
 * Does the reading merely repeat katakana that is already in the key?
 *
 * A writing such as the one for a company website is kanji followed by a
 * loanword in katakana, and JMdict's reading repeats that katakana verbatim.
 * Ruby over kana is noise: the katakana tail is precisely the part of the word a
 * learner does not need a reading for, and the kanji part is already covered by
 * its own shorter entry or by a kanji-run key.
 *
 * @param string $keb The kanji writing.
 * @param string $reading Its reading.
 * @return bool True when a katakana run of the writing reappears in the reading.
 */
function filter_ruby_is_kana_echo(string $keb, string $reading): bool {
    if (!preg_match_all('/[\p{Katakana}ー]+/u', $keb, $matches)) {
        return false;
    }
    foreach ($matches[0] as $run) {
        if (str_contains($reading, $run)) {
            return true;
        }
    }
    return false;
}

/**
 * Turn the frequency tags of one writing into a sortable band number.
 *
 * The nfNN tags split the JMdict headwords ranked by a newspaper corpus into
 * bands of roughly 490, so a lower number is a more common word, and they become
 * bands 1 to 48 here. A writing that carries no nf band can still be marked
 * ichi1 or spec1, which means the editors placed it in a curated list of
 * essential vocabulary that the corpus happens to miss; those are band 0 and are
 * always kept, because they are exactly the everyday words a newspaper ranking
 * under-reports. Second tier marks alone are band 49, the last thing kept.
 *
 * Inclusion and preference are separate. The band decides whether a writing is
 * kept; the rank decides which of two writings of the same word is believed, and
 * there a measured corpus position always beats a bare editorial mark.
 *
 * @param array $priorities Raw ke_pri values.
 * @return array|null Keys 'band' and 'rank', or null when the writing carries no frequency data.
 */
function filter_ruby_band(array $priorities): ?array {
    $band = null;
    $tier = null;
    foreach ($priorities as $priority) {
        $priority = trim($priority);
        if (preg_match('/^nf(\d{2})$/', $priority, $matches)) {
            $value = (int) $matches[1];
            $band = $band === null ? $value : min($band, $value);
        } else if (in_array($priority, ['news1', 'ichi1', 'spec1'], true)) {
            $tier = 0;
        } else if (in_array($priority, ['news2', 'ichi2', 'spec2', 'gai1', 'gai2'], true)) {
            $tier = $tier ?? 49;
        }
    }
    if ($band !== null) {
        return ['band' => $band, 'rank' => $band];
    }
    if ($tier === null) {
        return null;
    }
    return ['band' => $tier === 0 ? 0 : 49, 'rank' => $tier === 0 ? 49 : 50];
}

/**
 * Find the reading that belongs to one kanji writing.
 *
 * @param string $keb The kanji writing.
 * @param array $readings Reading records collected from the entry.
 * @return string|null The reading, or null when none applies.
 */
function filter_ruby_reading_for(string $keb, array $readings): ?string {
    foreach ($readings as $reading) {
        if ($reading['restr'] !== [] && !in_array($keb, $reading['restr'], true)) {
            continue;
        }
        if (filter_ruby_is_kana($reading['reb']) && mb_strlen($reading['reb']) <= 16) {
            return $reading['reb'];
        }
    }
    return null;
}

/**
 * Rank a writing by its hardest kanji.
 *
 * 1 is a word whose kanji are all taught in elementary school, 2 adds the rest
 * of the common-use set taught by the end of junior high, 3 is the name-use
 * set, and 4 is a word containing a kanji outside every official list. The
 * iteration marks are transparent: they repeat the previous character rather
 * than adding difficulty.
 *
 * @param string $keb The kanji writing.
 * @param array $grades Kanji => grade or null, from KANJIDIC2.
 * @return int Hardness rank from 1 to 4.
 */
function filter_ruby_hardness(string $keb, array $grades): int {
    $hardness = 1;
    preg_match_all('/\p{Han}/u', $keb, $matches);
    foreach ($matches[0] as $kanji) {
        if ($kanji === '々' || $kanji === '〆') {
            continue;
        }
        $grade = $grades[$kanji] ?? null;
        if ($grade === null) {
            $rank = 4;
        } else if ($grade >= 9) {
            $rank = 3;
        } else if ($grade === 8) {
            $rank = 2;
        } else {
            $rank = 1;
        }
        $hardness = max($hardness, $rank);
    }
    return $hardness;
}

/**
 * Split the collected writings into the four tiers and trim them by frequency.
 *
 * @param array $words Word records from filter_ruby_load_jmdict().
 * @param int $maxwords Largest number of writings to keep in one tier.
 * @param int $commonband Band up to which an unlisted-kanji word still counts as senior high.
 * @return array Tier name => ['words' => records, 'cutoff' => int, 'candidates' => int].
 */
function filter_ruby_split(array $words, int $maxwords, int $commonband): array {
    $buckets = ['elementary' => [], 'jhs' => [], 'shs' => [], 'university' => []];
    foreach ($words as $word) {
        if ($word['hardness'] === 1) {
            $tier = 'elementary';
        } else if ($word['hardness'] === 2) {
            $tier = 'jhs';
        } else if ($word['hardness'] === 3) {
            $tier = 'shs';
        } else {
            $tier = $word['band'] <= $commonband ? 'shs' : 'university';
        }
        $buckets[$tier][] = $word;
    }
    $tiers = [];
    foreach ($buckets as $tier => $bucket) {
        usort($bucket, function (array $left, array $right): int {
            return [$left['rank'], $left['seq'], $left['keb']] <=> [$right['rank'], $right['seq'], $right['keb']];
        });
        $counts = [];
        foreach ($bucket as $word) {
            $counts[$word['band']] = ($counts[$word['band']] ?? 0) + 1;
        }
        ksort($counts);
        $cutoff = 0;
        $running = $counts[0] ?? 0;
        foreach ($counts as $band => $count) {
            if ($band === 0) {
                continue;
            }
            if ($running + $count > $maxwords) {
                break;
            }
            $running += $count;
            $cutoff = $band;
        }
        $kept = array_values(array_filter($bucket, function (array $word) use ($cutoff): bool {
            return $word['band'] <= $cutoff;
        }));
        $tiers[$tier] = ['words' => $kept, 'cutoff' => $cutoff, 'candidates' => count($bucket)];
    }
    return $tiers;
}

/**
 * Build the exported word map for one tier, including kanji-run keys.
 *
 * A kanji-run key is emitted only for an inflecting word written as one run of
 * kanji followed by hiragana okurigana, and only when the reading ends with
 * exactly that okurigana, so that the remainder is provably the reading of the
 * kanji run. Where two words yield the same run the more frequent one wins, and
 * a run never displaces a whole-word entry.
 *
 * A run key is not a word entry, and the difference is the whole reason for the
 * two tests below. The runtime scanner matches the main map at every length
 * unconditionally, so a one-character run key fires on every occurrence of that
 * character the longer entries did not already cover — including occurrences
 * that are not an inflected stem at all. A run key therefore has to be right for
 * the whole kanji, not merely for the word it was derived from, and the readings
 * it would otherwise assert are exactly the wrong-reading harm decision D8
 * exists to prevent.
 *
 * Two conditions, both measured against the corpus rather than against this
 * tier's slice of it:
 *
 * 1. ONE DOMINANT READING. Of every standard JMdict writing shaped as this run
 *    plus okurigana, only one reading stem may carry a frequency tag. The
 *    frequency banding is the only evidence of commonness the sources offer, so
 *    a stem with no banded word has no measured weight at all and the surviving
 *    stem outweighs every rival infinitely; a second banded stem means the
 *    family has two live readings and the run is ambiguous. This is what refuses
 *    to choose between 'iku' and 'okonau', 'deru' and 'dasu', 'ikiru' and
 *    'umareru'.
 * 2. NO COMPETING WORD. The run must not itself be a writing the corpus
 *    measured as a word with a different reading, at any length and in any tier.
 *    This is what refuses the minute kanji, whose okurigana family is
 *    unambiguously 'wa' but which is overwhelmingly read 'fun' standing alone,
 *    and what stops the hesitation compound from claiming the stem of the verb
 *    built on it when the noun itself is a measured word read otherwise.
 *
 * @param array $words Word records kept for this tier.
 * @param array $families Kanji run => set of measured okurigana reading stems.
 * @param array $headwords Kanji-only writing => set of measured readings as a word.
 * @return array Writing => ['reading', 'rank', 'seq', 'run'], sorted for a stable diff.
 */
function filter_ruby_word_map(array $words, array $families, array $headwords): array {
    $map = [];
    foreach ($words as $word) {
        if (filter_ruby_is_kana_echo($word['keb'], $word['reading'])) {
            continue;
        }
        $map[$word['keb']] = [
            'reading' => $word['reading'],
            'rank' => $word['rank'],
            'seq' => $word['seq'],
            'run' => false,
        ];
    }
    $runs = [];
    foreach ($words as $word) {
        if (!$word['inflects']) {
            continue;
        }
        if (!preg_match('/^(\p{Han}+)(\p{Hiragana}+)$/u', $word['keb'], $matches)) {
            continue;
        }
        $run = $matches[1];
        $tail = $matches[2];
        if (!str_ends_with($word['reading'], $tail)) {
            continue;
        }
        $stem = mb_substr($word['reading'], 0, mb_strlen($word['reading']) - mb_strlen($tail));
        if ($stem === '' || !filter_ruby_is_kana($stem) || isset($map[$run])) {
            continue;
        }
        if (array_keys($families[$run] ?? []) !== [$stem]) {
            continue;
        }
        if (array_diff(array_keys($headwords[$run] ?? []), [$stem]) !== []) {
            continue;
        }
        $current = $runs[$run] ?? null;
        $better = [$word['rank'], $word['seq']] <=> [$current['rank'] ?? PHP_INT_MAX, $current['seq'] ?? 0];
        if ($current === null || $better < 0) {
            $runs[$run] = ['reading' => $stem, 'rank' => $word['rank'], 'seq' => $word['seq'], 'run' => true];
        }
    }
    foreach ($runs as $run => $record) {
        $map[$run] = $record;
    }
    ksort($map);
    return $map;
}

/**
 * Give every key one reading across all four tiers.
 *
 * The runtime layers the tiers in a fixed order and lets the later one win, so
 * two tiers disagreeing about a key means enabling one tier silently changes a
 * reading the teacher saw under another. Whichever reading is right, the data
 * must not hold both.
 *
 * The winner is the entry with the better evidence, decided in a fixed order: a
 * whole-word entry beats a kanji-run key, because a word entry is a reading
 * JMdict states for exactly that string while a run key is inferred; then the
 * better corpus rank; then the lower JMdict sequence number; then the reading
 * itself, so that the outcome never depends on iteration order. The winning
 * reading is written into every tier that holds the key, which keeps each tier
 * self-sufficient rather than moving entries between school levels.
 *
 * @param array $maps Tier name => key => ['reading', 'rank', 'seq', 'run'].
 * @return array Keys 'maps' (tier => key => reading) and 'collisions' (list of descriptions).
 */
function filter_ruby_resolve_collisions(array $maps): array {
    $winners = [];
    foreach ($maps as $tier => $map) {
        foreach ($map as $key => $entry) {
            $order = [$entry['run'] ? 1 : 0, $entry['rank'], $entry['seq'], $entry['reading']];
            if (!isset($winners[$key]) || $order < $winners[$key]['order']) {
                $winners[$key] = ['order' => $order, 'reading' => $entry['reading']];
            }
        }
    }
    $collisions = [];
    $resolved = [];
    foreach ($maps as $tier => $map) {
        foreach ($map as $key => $entry) {
            $reading = $winners[$key]['reading'];
            if ($reading !== $entry['reading']) {
                $collisions[] = $key . ': ' . $tier . ' had ' . $entry['reading'] . ', resolved to ' . $reading;
            }
            $resolved[$tier][$key] = $reading;
        }
    }
    return ['maps' => $resolved, 'collisions' => $collisions];
}

/**
 * Build the gated single-kanji fallback map for each tier.
 *
 * When JMdict lists the bare kanji as a word with a measured corpus band, that
 * reading is used: it is direct evidence of how the character is read on its own,
 * and better evidence than the order of the KANJIDIC2 reading list, which puts
 * the archaic shirogane for silver ahead of the modern gin. Where JMdict has no
 * measured reading the KANJIDIC2 rule stands, and it is sometimes wrong; that is
 * why the whole map is gated behind a setting that defaults to off. A measured
 * reading that is not plain hiragana is refused as well, because a handful of
 * kanji are JMdict headwords only as loanword ateji, and 'senchi' in katakana is
 * not a reading of the coral kanji.
 *
 * Every kanji on an official list gets an entry in the tier of its own grade.
 * A kanji outside those lists earns an entry only when it actually occurs in a
 * word this build kept, and lands in senior high when KANJIDIC2 gives it a
 * newspaper frequency rank and in university when it does not.
 *
 * KANJIDIC2 also catalogues the CJK compatibility ideographs, which are variant
 * codepoints for characters it lists separately and which carry a name-use grade
 * of their own. Every one of them is refused here: a browser folds them away, so
 * such an entry could never match anything, and its normalised form is already
 * in the data. See {@see filter_ruby_is_normalised()}.
 *
 * @param array $index KANJIDIC2 index from filter_ruby_load_kanjidic().
 * @param array $used Kanji that occur in a kept word, as a set.
 * @param array $lone Kanji => ['reading', 'rank'], for kanji JMdict measures as a word on their own.
 * @return array Tier name => kanji => reading.
 */
function filter_ruby_kanji_maps(array $index, array $used, array $lone): array {
    $maps = ['elementary' => [], 'jhs' => [], 'shs' => [], 'university' => []];
    foreach ($index['reading'] as $kanji => $reading) {
        if (!filter_ruby_is_normalised($kanji) || !filter_ruby_is_normalised($reading)) {
            continue;
        }
        $grade = $index['grade'][$kanji] ?? null;
        if ($grade !== null && $grade <= 6) {
            $tier = 'elementary';
        } else if ($grade === 8) {
            $tier = 'jhs';
        } else if ($grade !== null) {
            $tier = 'shs';
        } else if (!isset($used[$kanji])) {
            continue;
        } else {
            $tier = ($index['freq'][$kanji] ?? null) === null ? 'university' : 'shs';
        }
        $measured = $lone[$kanji]['reading'] ?? '';
        if ($measured !== '' && preg_match('/^\p{Hiragana}+$/u', $measured)) {
            $reading = $measured;
        }
        $maps[$tier][$kanji] = $reading;
    }
    foreach ($maps as $tier => $map) {
        ksort($map);
        $maps[$tier] = $map;
    }
    return $maps;
}

/**
 * Render one tier file.
 *
 * The header deliberately omits the Moodle GPL boilerplate and names CC BY-SA
 * 4.0 as the licence. data/ is declared as third party content in
 * thirdpartylibs.xml, so moodle-plugin-ci excludes it from phpcs, phpdoc,
 * phplint, phpmd and phpcpd (Bridge/MoodlePlugin.php getFiles(), which calls
 * notPath() on every declared location), and core's admin/thirdpartylibs.php
 * reports the whole directory to administrators as CC BY-SA 4.0. Shipping the
 * GPL boilerplate inside bytes the site itself advertises as CC BY-SA would be
 * a contradiction, and the share-alike term forbids the relicensing anyway.
 *
 * Run directly against data/, phpcs still reports the missing boilerplate and
 * warns that the licence value is not the GPL: moodle-cs hard-codes the GNU URL
 * (FileExpectedTagsSniff::$preferredLicenseRegex), which is precisely the sniff
 * asserting the file is Moodle code. It is not, and CI never applies it here.
 *
 * @param string $tier Tier name.
 * @param array $words Writing => reading.
 * @param array $kanji Kanji => reading.
 * @param array $provenance Source versions, keys 'jmdict' and 'kanjidic'.
 * @return string The complete file contents.
 */
function filter_ruby_render(string $tier, array $words, array $kanji, array $provenance): string {
    $labels = [
        'elementary' => 'elementary school',
        'jhs' => 'junior high school',
        'shs' => 'senior high school',
        'university' => 'university',
    ];
    $out = "<?php\n";
    $out .= "/**\n";
    $out .= ' * Furigana readings for words at ' . $labels[$tier] . " level.\n";
    $out .= " *\n";
    $out .= " * GENERATED FILE - DO NOT EDIT BY HAND.\n";
    $out .= " * Regenerate with cli/build_tiers.php.\n";
    $out .= " *\n";
    $out .= ' * Derived from JMdict (' . $provenance['jmdict'] . ') and KANJIDIC2 ('
        . $provenance['kanjidic'] . "), which are the property of\n";
    $out .= " * the Electronic Dictionary Research and Development Group, Monash University.\n";
    $out .= " *\n";
    $out .= " * This file holds derived third party data, not Moodle code. The whole of\n";
    $out .= " * data/ is declared in thirdpartylibs.xml, which is what the site's Third\n";
    $out .= " * party libraries admin page reads to tell an administrator that these bytes\n";
    $out .= " * are CC BY-SA 4.0. It therefore carries the licence of its sources and not\n";
    $out .= " * the Moodle GPL boilerplate, exactly as core's own declared third party\n";
    $out .= " * directories do. Creative Commons lists CC BY-SA 4.0 as one-way compatible\n";
    $out .= " * with GPLv3, which is what lets it ship inside this GPL plugin.\n";
    $out .= " *\n";
    $out .= " * @copyright  Electronic Dictionary Research and Development Group, Monash University\n";
    $out .= " * @license    https://creativecommons.org/licenses/by-sa/4.0/ CC BY-SA 4.0\n";
    $out .= " */\n\n";
    $out .= "defined('MOODLE_INTERNAL') || die();\n\n";
    $out .= "return [\n";
    $out .= "    'words' => [\n";
    foreach ($words as $key => $value) {
        $out .= "        '" . $key . "' => '" . $value . "',\n";
    }
    $out .= "    ],\n";
    $out .= "    'kanji' => [\n";
    foreach ($kanji as $key => $value) {
        $out .= "        '" . $key . "' => '" . $value . "',\n";
    }
    $out .= "    ],\n";
    $out .= "];\n";
    return $out;
}

/**
 * Read the creation date recorded in the JMdict comment block.
 *
 * @param string $path Path to JMdict_e or JMdict_e.gz.
 * @return string The date, or 'unknown' when the comment is absent.
 */
function filter_ruby_jmdict_date(string $path): string {
    $handle = gzopen($path, 'rb');
    if ($handle === false) {
        return 'unknown';
    }
    $date = 'unknown';
    while (($line = gzgets($handle)) !== false) {
        if (preg_match('/JMdict created:\s*([0-9-]+)/', $line, $matches)) {
            $date = $matches[1];
            break;
        }
        if (str_contains($line, '<JMdict>')) {
            break;
        }
    }
    gzclose($handle);
    return $date;
}

/**
 * Run the whole build.
 *
 * @param array $options Options from filter_ruby_options().
 * @return void
 */
function filter_ruby_build_tiers(array $options): void {
    $log = function (string $message) use ($options): void {
        if (!$options['quiet']) {
            echo $message . PHP_EOL;
        }
    };
    if (!is_dir($options['outdir']) || !is_writable($options['outdir'])) {
        filter_ruby_fail("Output directory is not writable: {$options['outdir']}");
    }
    if (!class_exists('Normalizer')) {
        filter_ruby_fail('The intl extension is required: the build refuses codepoints that are not in NFC.');
    }
    $log('Reading ' . $options['kanjidic'] . ' ...');
    $index = filter_ruby_load_kanjidic($options['kanjidic']);
    $log('  kanji indexed: ' . count($index['grade']) . ', with a fallback reading: ' . count($index['reading']));
    $log('Reading ' . $options['jmdict'] . ' ...');
    $loaded = filter_ruby_load_jmdict($options['jmdict'], $index);
    $jmdict = $loaded['words'];
    $families = $loaded['families'];
    $headwords = $loaded['headwords'];
    $log('  usable kanji writings with a frequency tag: ' . count($jmdict));
    $log('  kanji runs with a measured okurigana family: ' . count($families)
        . ', kanji-only writings measured as words: ' . count($headwords));
    $provenance = [
        'jmdict' => filter_ruby_jmdict_date($options['jmdict']),
        'kanjidic' => $index['version'] === '' ? $index['date'] : $index['version'],
    ];
    // A single character whole-word entry would be matched at any position and is
    // exactly the ambiguous case the contract reserves for the gated fallback map
    // (a bare 'book' kanji is far more often 'hon' than JMdict's equally tagged
    // 'moto'), so single character headwords only inform the fallback map. Only a
    // reading backed by a measured corpus band is trusted there.
    $lone = [];
    $measured = [];
    $multi = [];
    foreach ($jmdict as $word) {
        if (mb_strlen($word['keb']) !== 1) {
            $multi[] = $word;
            continue;
        }
        $lone[$word['keb']] = $word['reading'];
        if ($word['rank'] <= 48) {
            $measured[$word['keb']] = ['reading' => $word['reading'], 'rank' => $word['rank']];
        }
    }
    ksort($lone);
    ksort($measured);
    $log('  of which whole words: ' . count($multi) . ', bare kanji headwords: ' . count($lone)
        . ' (' . count($measured) . ' with a measured band)');
    $tiers = filter_ruby_split($multi, $options['maxwords'], $options['commonband']);
    $used = [];
    foreach ($tiers as $tier) {
        foreach ($tier['words'] as $word) {
            preg_match_all('/\p{Han}/u', $word['keb'], $matches);
            foreach ($matches[0] as $kanji) {
                $used[$kanji] = true;
            }
        }
    }
    $kanjimaps = filter_ruby_kanji_maps($index, $used, $measured);
    $maps = [];
    foreach ($tiers as $tier => $bucket) {
        $maps[$tier] = filter_ruby_word_map($bucket['words'], $families, $headwords);
    }
    $resolution = filter_ruby_resolve_collisions($maps);
    foreach ($resolution['collisions'] as $collision) {
        $log('  cross-tier collision resolved: ' . $collision);
    }
    $log('  cross-tier key collisions: ' . count($resolution['collisions']));
    foreach ($tiers as $tier => $bucket) {
        $words = $resolution['maps'][$tier];
        $kanji = $kanjimaps[$tier];
        $runs = count(array_filter($maps[$tier], function (array $entry): bool {
            return $entry['run'];
        }));
        $file = $options['outdir'] . '/tier_' . $tier . '.php';
        if (file_put_contents($file, filter_ruby_render($tier, $words, $kanji, $provenance)) === false) {
            filter_ruby_fail("Cannot write {$file}");
        }
        $log(sprintf(
            '%-11s candidates %5d, kept bands 0-%s, words %4d (of which runs %4d), kanji %4d -> %s',
            $tier,
            $bucket['candidates'],
            $bucket['cutoff'] >= 49 ? 'all' : sprintf('nf%02d', $bucket['cutoff']),
            count($words),
            $runs,
            count($kanji),
            basename($file)
        ));
    }
}
