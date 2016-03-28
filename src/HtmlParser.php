<?php
namespace PhpKit\Html5Tools;

use PhpKit\Html5Tools\Interfaces\ParserObserverInterface;
const PARSE_ATTR                = 'ATT';
const PARSE_OPEN_TAG            = 'OPE';
const PARSE_TAG_NAME_OR_COMMENT = 'TAG';
const PARSE_TEXT                = 'TXT';
const PARSE_VALUE_OR_ATTR       = 'VAT';

/**
 * A streaming HTML5 parser.
 *
 * <p>The parser instances are meant to be used and then discarded, not to be reused or to be persistent in any way.
 * You cannot parse markup with the same instance more than once.
 */
class HtmlParser
{
  const IS_STARTING_INSIDE_TAG = <<<'REGEX'
%
  ^ [^<]* >         # anything not an < up to the first >
%xu
REGEX;

  const MATCH = [

    PARSE_TEXT => <<<'REGEX'
%
  ^ (?P<t> [^<]*)   # anything up to the next < or the string end
%xu
REGEX
    ,

    PARSE_OPEN_TAG => <<<'REGEX'
%
  ^ (?P<t> < )      # must be an <
%xu
REGEX
    ,

    PARSE_TAG_NAME_OR_COMMENT => <<<'REGEX'
%
  ^
  (?P<t>
    /?              # optional /
    (?:             # either
      ! .*? >       # HTML comment or doctype
      |             # or
      \? .*? \?>    # XML Processing Instruction
      |             # or
      [\w\-\:]+     # tag name, with optional prefix
    )
  )
%xus
REGEX
    ,

    PARSE_ATTR => <<<'REGEX'
%
  ^
  (?P<s> \s*)
  (?P<t>
    (?:             # either
      /? >          # end of tag: /> or >
      |             # or
      [^\s=/>]+     # attribute name (anything up to a space, =, /> or >)
    )
  )
%xu
REGEX
    ,

    PARSE_VALUE_OR_ATTR => <<<'REGEX'
%
  ^
  (?P<s> \s*)
  (?P<t>
    (?:                           # either
      /? >                        # end of tag: /> or >
      |                           # or
      = \s* "[^"]* (?: " | $)     # ="quoted" or ="unclosed
      |                           # or
      = \s* '[^']* (?: ' | $)     # ='quoted' or ='unclosed
      |                           # or
      = \s* (?! " | ') [^\s>]+    # =unquoted (up to the first space or >)
      |                           # or
      [^\s=/>]+                   # next attribute name (current attribute has no value)
    )
  )
%xu
REGEX
    ,
  ];

  /**
   * @var ParserObserverInterface
   */
  private $observer = null;
  /**
   * @var string[]
   */
  private $tagStack = [];

  /**
   * Parses a block of HTML5 markup, calling the registered event listeners at each step of the parsing process.
   *
   * <p>**Warning:** do not call this method more than once and do not reuse the parser instance.
   *
   * <p>The markup can be an whole document or just a fragment of it. In the later case, it can start at any point of
   * the original document, even at invalid locations (ex. midway a tag or attribute). The way the parser handles an
   * invalid starting location is to consider the initial markup to be plain text until a valid tag begins.
   *
   * ><p>**Note:** XML Processing Instructions (ex. `<?name value ?>`) and Markup Declarations other than DOCTYPE
   * (ex. `<!something>`) are parsed as HTML comments, as they are not meaningful on the HTML5 grammar.
   *
   * @param string $html
   */
  function parse ($html)
  {
    if (!$this->observer)
      throw new \RuntimeException ("No parser observer was set");
    $state = PARSE_TEXT;
    $attr  = null;

    // Check if the input starts midway a tag.
    if (preg_match (self::IS_STARTING_INSIDE_TAG, $html, $m)) {
      $this->observer->invalidMarkupParsed ($m[0]);
      // Skip invalid/unrecognized markup to the next start of tag.
      $html = substr ($html, strlen ($m[0]));
    }

    while (preg_match (self::MATCH[$state], $html, $m)) {

      if (isset($m['s']))
        $this->observer->whiteSpaceParsed ($m['s']);

      $v = $m['t'];

      switch ($state) {

        case PARSE_ATTR:
          if ($v[0] == '/' || $v == '>') {
            if (isset($attr)) {
              $this->observer->attrParsed ($attr);
              $attr = null;
            }
            $this->observer->closeTagParsed (array_pop ($this->tagStack), $v[0] == '/');
            $state = PARSE_TEXT;
          }
          else {
            $attr  = $v;
            $state = PARSE_VALUE_OR_ATTR;
          }
          break;

        case PARSE_OPEN_TAG:
          $state = PARSE_TAG_NAME_OR_COMMENT;
          break;

        case PARSE_TAG_NAME_OR_COMMENT:
          if ($v[0] == '!' || $v[0] == '?') {
            if (substr (strtoupper ($v), 0, 8) == '!DOCTYPE')
              $this->observer->doctypeParsed ("<$v");
            else $this->observer->commentParsed ("<$v");
            $state = PARSE_TEXT;
          }
          else {
            $this->tagStack[] = $v;
            $this->observer->openTagParsed ($v);
            $state = PARSE_ATTR;
          }
          break;

        case PARSE_TEXT:
          $this->observer->textParsed ($v);
          $state = PARSE_OPEN_TAG;
          break;

        case PARSE_VALUE_OR_ATTR:
          if ($v[0] == '=') {
            if (($q = $v[1]) == '"' || $v[1] == "'")
              $v = substr ($v, 2, -1);
            else {
              $q = '';
              $v = substr ($v, 1);
            }
            $this->observer->attrParsed ($attr, $v, $q);
            $attr  = null;
            $state = PARSE_ATTR;
          }
          elseif ($v[0] == '/' || $v == '>') {
            if (isset($attr)) {
              $this->observer->attrParsed ($attr);
              $attr = null;
            }
            $this->observer->closeTagParsed (array_pop ($this->tagStack), $v[0] == '/');
            $state = PARSE_TEXT;
          }
          else {
            $attr = $v;
            // Keep the same state.
          }
          break;
      }
      $html = substr ($html, strlen ($m[0]));
    }
    // output remaining unparsed (syntactically invalid) markup
    if ($html !== '')
      $this->observer->invalidMarkupParsed ($html);
  }

  /**
   * Registers a parser observer.
   *
   * @param ParserObserverInterface $observer
   */
  function setObserver (ParserObserverInterface $observer)
  {
    $this->observer = $observer;
  }

}
