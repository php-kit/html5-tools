<?php
namespace PhpKit\Html5Tools;

const PARSE_ATTR                = 'ATT';
const PARSE_OPEN_TAG            = 'OPE';
const PARSE_TAG_NAME_OR_COMMENT = 'TAG';
const PARSE_TEXT                = 'TXT';
const PARSE_VALUE_OR_ATTR       = 'VAT';

/** Args: `string $tagName` */
const OPEN_TAG_PARSED = 'TAG';
/**
 * Args: `string $attrName, string [optional] $value, string [optional] $quote`
 * > `$value` is absent if the attribute has no value.<br>
 * > `$quote` is `"`, `'` or an empty string. It is absent if the attribute has no value.
 */
const ATTR_PARSED = 'ATT';
/** Args: `string $tagName, bool $autoClose` */
const CLOSE_TAG_PARSED = 'CLO';
/** Args: `string $text` */
const TEXT_PARSED = 'TXT';
/** Args: `string $comment` */
const COMMENT_PARSED = 'COM';
/** Args: `string $doctype` */
const DOCTYPE_PARSED = 'DOC';
/** Args: `string $space` */
const WHITE_SPACE_PARSED = 'SPC';

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
      ! .*? >       # HTML comment or PI
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

  private $events = [
    OPEN_TAG_PARSED    => [],
    ATTR_PARSED        => [],
    CLOSE_TAG_PARSED   => [],
    TEXT_PARSED        => [],
    COMMENT_PARSED     => [],
    DOCTYPE_PARSED     => [],
    WHITE_SPACE_PARSED => [],
  ];

  private $tagStack = [];

  /**
   * Calls the registered event listeners for a given event.
   *
   * @param string $event Event name. One of the XXX_PARSED constants.
   * @param mixed  $args  [option] Event-specific arguments (for instance, a tag name).
   */
  function emmit ($event, ...$args)
  {
    foreach ($this->events[$event] as $listener)
      $listener (...$args);
  }

  /**
   * Registers an event listener.
   *
   * ><p>**Note:** there is no method to unregister a listener; the parser instances are meant to be used and then
   * discarded, not to be reused or to be persistent in any way, so there is no use for event unregistration.
   *
   * @param string   $event    Event name. One of the XXX_PARSED constants.
   * @param callable $listener The event listener callback.
   */
  function on ($event, callable $listener)
  {
    if (!isset($this->events[$event]))
      throw new \InvalidArgumentException ("Invalid event: $event");

    $this->events[$event][] = $listener;
  }

  /**
   * Parses a block of HTML5 markup, calling the registered event listeners at each step of the parsing process.
   *
   * <p>**Warning:** do not call this method more than once and do not reuse the parser instance.
   *
   * <p>The markup can be an whole document or just a fragment of it. In the later case, it can start at any point of
   * the original document, even at invalid locations (ex. midway a tag or attribute). The way the parser handles an
   * invalid starting location is to consider the initial markup to be plain text until a valid tag begins.
   *
   * <p>XML Processing Instruction (PI) are parsed as HTML comments, **except** for DOCTYPE declarations.
   *
   * @param string $html
   */
  function parse ($html)
  {
    $state = PARSE_TEXT;
    $attr  = null;

    // Check if the input starts midway a tag.
    if (preg_match (self::IS_STARTING_INSIDE_TAG, $html, $m)) {
      // Output markup up to the tag end as normal text, as we can't parse it correctly.
      $this->emmit (TEXT_PARSED, $m[0]);
      $html = substr ($html, strlen ($m[0]));
    }

    while (preg_match (self::MATCH[$state], $html, $m)) {

      if (isset($m['s']))
        $this->emmit (WHITE_SPACE_PARSED, $m['s']);

      $v = $m['t'];

      switch ($state) {

        case PARSE_ATTR:
          if ($v[0] == '/' || $v == '>') {
            if (isset($attr)) {
              $this->emmit (ATTR_PARSED, $attr);
              $attr = null;
            }
            $this->emmit (CLOSE_TAG_PARSED, array_pop ($this->tagStack), $v[0] == '/');
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
          if ($v[0] == '!') {
            $this->emmit (substr (strtoupper ($v), 0, 8) == '!DOCTYPE' ? DOCTYPE_PARSED : COMMENT_PARSED, "<$v");
            $state = PARSE_TEXT;
          }
          else {
            $this->tagStack[] = $v;
            $this->emmit (OPEN_TAG_PARSED, $v);
            $state = PARSE_ATTR;
          }
          break;

        case PARSE_TEXT:
          $this->emmit (TEXT_PARSED, $v);
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
            $this->emmit (ATTR_PARSED, $attr, $v, $q);
            $attr  = null;
            $state = PARSE_ATTR;
          }
          elseif ($v[0] == '/' || $v == '>') {
            if (isset($attr)) {
              $this->emmit (ATTR_PARSED, $attr);
              $attr = null;
            }
            $this->emmit (CLOSE_TAG_PARSED, array_pop ($this->tagStack), $v[0] == '/');
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
      $this->emmit (TEXT_PARSED, $html);
  }

}
