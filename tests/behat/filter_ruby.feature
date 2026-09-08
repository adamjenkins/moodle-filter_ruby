@filter @filter_ruby
Feature: Furigana readings are added to kanji wherever Moodle displays text
  In order to read Japanese course material that is above my kanji level
  As a student
  I need difficult words annotated with the reading a teacher or the site has given them

  # Notes on the choices made in this file, so the next person does not have to rediscover them.
  #
  # 1. Word length is NOT gated by the lone-kanji setting. The main dictionary is matched at
  #    every length from the longest entry down to ONE, unconditionally
  #    (classes/local/annotator.php:401, longest_match(), whose loop runs `$length >= 1`). What
  #    the setting gates is a separate last-resort layer, consulted only where the dictionary
  #    matched nothing at that position at any length, which guesses a reading for a lone kanji
  #    (classes/local/annotator.php:433, fallback()). So an explicit one-character entry — a
  #    teacher's 金=かね, or a tier file's kanji-run key — is annotated whether the setting is on
  #    or off.
  #
  #    That was not always true: an earlier build implemented the setting as a minimum lookup
  #    length, so with it off the scan stopped at length 2 and every single-character entry was
  #    silently dropped. The scenarios below were originally written with two-character words
  #    only, to dodge that bug. They are left that way — they earn their keep testing the
  #    compound and longest-match paths — and the regression itself is pinned by
  #    "A single-kanji course word list entry is annotated with the fallback off".
  #
  # 2. The readings are asserted twice over: once as visible text, and once as an xpath match on
  #    the generated <ruby class="filter_ruby">…<rt>reading</rt></ruby> structure the annotator
  #    contracts to emit (classes/local/annotator.php:466-467). The text assertion alone would
  #    pass on a reading that had been dumped into the page as plain text; the xpath assertion
  #    alone would pass on ruby that the browser never shows.
  #
  # 3. Unlike the SVG case in filter_sheetmusic, no local-name() gymnastics are needed: <ruby>,
  #    <rt> and <code> are HTML elements in no namespace, so plain XPath name tests match them.
  #
  # 4. No @javascript anywhere. This filter is entirely server side in the default 'all' display
  #    mode — no AMD module is loaded for it at all (classes/text_filter.php, JS_MODES covers only
  #    'hover' and 'toggle') — so the behaviour under test must hold with no JavaScript running.
  #
  # 5. The pipe of the inline syntax is written \| inside Gherkin table cells, where an unescaped
  #    pipe would end the cell. It is written plainly in ordinary step arguments.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Taro      | Teacher  | teacher1@example.com |
      | student1 | Hanako    | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname   | shortname | category |
      | Japanese 1 | JPN1      | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | JPN1   | editingteacher |
      | student1 | JPN1   | student        |
    And the "ruby" filter is "on"

  Scenario: An admin sets a site word list and the readings appear on a course page
    Given the following "activities" exist:
      | activity | course | name       | content                | contentformat |
      | page     | JPN1   | Lesson one | <p>漢字の勉強をする</p> | 1             |
    And I log in as "admin"
    And I visit "/admin/settings.php?section=filtersettingruby"
    And I set the field "s_filter_ruby_wordlist" to multiline:
    """
    漢字=かんじ
    勉強=べんきょう
    """
    When I press "Save changes"
    Then I should see "Changes saved"
    And I should not see "Some settings were not changed due to an error."
    When I am on the "Lesson one" "page activity" page logged in as "student1"
    Then I should see "かんじ"
    And I should see "べんきょう"
    And "//ruby[contains(concat(' ', normalize-space(@class), ' '), ' filter_ruby ')][contains(., '漢字')]/rt[normalize-space(.) = 'かんじ']" "xpath_element" should exist
    And "//ruby[contains(concat(' ', normalize-space(@class), ' '), ' filter_ruby ')][contains(., '勉強')]/rt[normalize-space(.) = 'べんきょう']" "xpath_element" should exist

  Scenario: A teacher's course word list overrides the site reading for that course only
    Given the following config values are set as admin:
      | wordlist | 今日=こんにち | filter_ruby |
    And the following "activities" exist:
      | activity | course | name       | content           | contentformat |
      | page     | JPN1   | Lesson two | <p>今日の授業</p> | 1             |
    And I am on the "JPN1" "course" page logged in as "teacher1"
    When I navigate to "Filters" in current page administration
    Then I should see "Filter settings in Course: Japanese 1"
    When I click on the "Settings" link in the table row containing "Ruby (furigana)"
    Then I should see "Filter settings for Ruby (furigana) in Course: Japanese 1"
    When I set the field "Word list" to multiline:
    """
    今日=きょう
    """
    And I press "Save changes"
    And I click on the "Settings" link in the table row containing "Ruby (furigana)"
    # Read the setting back out of the form: the redirect after a save says nothing about
    # whether save_changes() actually wrote a filter_config row for this course context.
    Then the field "Word list" matches multiline:
    """
    今日=きょう
    """
    When I am on the "Lesson two" "page activity" page logged in as "student1"
    Then I should see "きょう"
    And I should not see "こんにち"
    And "//ruby[contains(concat(' ', normalize-space(@class), ' '), ' filter_ruby ')][contains(., '今日')]/rt[normalize-space(.) = 'きょう']" "xpath_element" should exist

  Scenario: A single-kanji course word list entry is annotated with the fallback off
    # The regression this pins: the lone-kanji setting once doubled as a minimum lookup length,
    # so with it off a one-character entry was silently dropped and this scenario would fail.
    # The entry is one kanji and the setting is explicitly Off, so the reading can only have come
    # from the word list — the guess layer that the same Off shuts is the only other source.
    Given the following "activities" exist:
      | activity | course | name         | content        | contentformat |
      | page     | JPN1   | Lesson three | <p>金の話</p>  | 1             |
    And I am on the "JPN1" "course" page logged in as "teacher1"
    When I navigate to "Filters" in current page administration
    And I click on the "Settings" link in the table row containing "Ruby (furigana)"
    And I set the field "Word list" to multiline:
    """
    金=かね
    """
    And I set the field "Single-kanji fallback" to "Off"
    And I press "Save changes"
    And I click on the "Settings" link in the table row containing "Ruby (furigana)"
    # Read both settings back: a redirect after the save proves nothing about what was stored,
    # and "Off" in particular must be an explicit stored 0, not the inherited "Site default".
    Then the field "Word list" matches multiline:
    """
    金=かね
    """
    And the field "Single-kanji fallback" matches value "Off"
    When I am on the "Lesson three" "page activity" page logged in as "student1"
    Then I should see "かね"
    And "//ruby[contains(concat(' ', normalize-space(@class), ' '), ' filter_ruby ')][contains(., '金')]/rt[normalize-space(.) = 'かね']" "xpath_element" should exist
    # 話 is in no list at any level, so with the guess layer off it has to stay bare. Without this
    # the scenario would still pass if the fallback had quietly annotated every kanji on the page.
    And "//ruby[contains(concat(' ', normalize-space(@class), ' '), ' filter_ruby ')][contains(., '話')]" "xpath_element" should not exist

  Scenario: Inline markup gives a reading with no word list configured anywhere
    Given the following "activities" exist:
      | activity | course | name        | content                          | contentformat |
      | page     | JPN1   | Inline note | <p>{漢字\|かんじ}を読む</p>      | 1             |
    When I am on the "Inline note" "page activity" page logged in as "student1"
    Then I should see "かんじ"
    And I should not see "{漢字|かんじ}"
    And "//ruby[contains(concat(' ', normalize-space(@class), ' '), ' filter_ruby ')][contains(., '漢字')]/rt[normalize-space(.) = 'かんじ']" "xpath_element" should exist

  Scenario: A word inside a code block is left exactly as the author wrote it
    Given the following config values are set as admin:
      | wordlist | 漢字=かんじ | filter_ruby |
    And the following "activities" exist:
      | activity | course | name        | content                                     | contentformat |
      | page     | JPN1   | Code lesson | <p>漢字</p><p><code>漢字</code></p>         | 1             |
    When I am on the "Code lesson" "page activity" page logged in as "student1"
    # The paragraph outside the code block proves the filter really did run on this page,
    # so that the negative assertions below cannot pass just because nothing happened at all.
    Then I should see "かんじ"
    And "//ruby[contains(concat(' ', normalize-space(@class), ' '), ' filter_ruby ')]/rt[normalize-space(.) = 'かんじ']" "xpath_element" should exist
    And "//code//ruby" "xpath_element" should not exist
    And "//code[normalize-space(.) = '漢字']" "xpath_element" should exist
