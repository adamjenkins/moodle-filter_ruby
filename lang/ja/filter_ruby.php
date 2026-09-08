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
 * Strings for component 'filter_ruby', language 'ja'.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['displaymode'] = '表示方法';
$string['displaymode_all'] = '常に表示する（出現するたびに）';
$string['displaymode_first'] = '各単語の最初の1回のみ表示する';
$string['displaymode_help'] = 'ふりがなを読み手にどのように見せるかを設定します。

* **常に表示する（出現するたびに）**：一致した単語には、出現するたびにふりがなを付けます。
* **各単語の最初の1回のみ表示する**：ページ内で最初に出現したときだけふりがなを付け、以降はそのまま表示します。
* **マウスを重ねたときに表示する**：ふりがなは隠れており、単語にマウスを重ねる（またはフォーカスする）と表示されます。
* **読み手が切り替える**：ふりがなは隠れており、読み手がふりがなボタンで表示します。選択した状態は記憶されます。';
$string['displaymode_hover'] = 'マウスを重ねたときに表示する';
$string['displaymode_toggle'] = '読み手が切り替える（ボタン）';
$string['edrdgacknowledgement'] = '同梱のレベル別辞書は、JMdict/EDICT および KANJIDIC2 のファイルから作成しています。これらのファイルは Electronic Dictionary Research and Development Group が権利を保有しており、同グループのライセンスであるクリエイティブ・コモンズ 表示 - 継承 4.0 国際 (CC BY-SA 4.0) に従って利用しています。詳細は <a href="https://www.edrdg.org/edrdg/licence.html">https://www.edrdg.org/edrdg/licence.html</a> をご覧ください。';
$string['filtername'] = 'ルビ（ふりがな）';
$string['lonekanji'] = '漢字1字への読み補完';
$string['lonekanji_help'] = 'どのリストの単語にも一致しなかったとき、漢字1字にもっとも一般的な読みを補って表示します。

初期設定ではオフであり、ほとんどのコースではオフのままにすることをおすすめします。文脈から切り離された漢字1字には、誤った読みが付くことが少なくないためです（たとえば「今日」は「きょう」であって、「いま」＋「ひ」ではありません）。オフにしておくと、フィルタは確実でない箇所には何も付けません。';
$string['pluginname'] = 'ルビフィルタ';
$string['privacy:preference:show'] = '表示方法が「読み手が切り替える」のとき、読み手がふりがなを表示するか非表示にするかを選んだ設定です。';
$string['problem_emptyword'] = '{$a->line} 行目：「=」の前が空です（{$a->text}）。各行は「単語=読み」の形式で入力してください。';
$string['problem_noseparator'] = '{$a->line} 行目：「=」が見つかりません（{$a->text}）。各行は「単語=読み」の形式で入力してください。';
$string['problem_notkana'] = '{$a->line} 行目：読みがかなで書かれていません（{$a->text}）。この行はそのまま保存しました。';
$string['tier_elementary'] = '小学校レベルの語';
$string['tier_elementary_help'] = 'もっとも難しい漢字が小学校（1〜6年生）で習う漢字である語に、ふりがなを付けます。';
$string['tier_jhs'] = '中学校レベルの語';
$string['tier_jhs_help'] = 'もっとも難しい漢字が中学校で習う漢字である語に、ふりがなを付けます。';
$string['tier_shs'] = '高等学校レベルの語';
$string['tier_shs_help'] = 'もっとも難しい漢字が中学校で習う範囲を超える語（人名用漢字を含む）に、ふりがなを付けます。';
$string['tier_university'] = '大学レベル・まれな語';
$string['tier_university_help'] = '常用漢字表にない、もっともまれな漢字を含む語に、ふりがなを付けます。';
$string['tiers'] = 'レベル別辞書';
$string['tiers_help'] = 'レベルにチェックを入れることは「このレベルの語にふりがなを付ける」という意味であり、「読み手がこのレベルをすでに知っている」という意味ではありません。各レベルは独立しているため、小学校レベルの語はそのままにして、大学レベルの語だけにふりがなを付けることもできます。';
$string['togglebutton'] = 'ふりがな';
$string['wordlist'] = '単語リスト';
$string['wordlist_help'] = '1行に1件、「単語=読み」の形式で入力します。例：漢字=かんじ

* 空行と # で始まる行は無視します。
* 同じ単語が複数の行にある場合は、あとの行が優先されます。
* 読みを空にすると（例：金= ）、その単語にはふりがなを付けません。
* このリストはサイトのリストより優先され、サイトのリストはレベル別辞書より優先されます。';
