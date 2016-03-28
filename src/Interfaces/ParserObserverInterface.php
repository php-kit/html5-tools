<?php
namespace PhpKit\Html5Tools\Interfaces;

interface ParserObserverInterface
{
  /**
   * @param string      $name  Attribute name.
   * @param string|null $value Attribute value. It is null if the attribute has no value.
   * @param string|null $quote Enclosing quote. It is ", ' or an empty string. It is null if the attribute has no value.
   */
  function attrParsed ($name, $value = null, $quote = null);

  /**
   * @param string $name
   * @param bool   $autoClose
   */
  function closeTagParsed ($name, $autoClose);

  /**
   * Handles a comment.
   *
   * <p>Note that the received argument can also be an XML PI or a Markup Declaration (other than DOCTYPE).
   *
   * @param string $comment
   */
  function commentParsed ($comment);

  /**
   * @param string $doctype
   */
  function doctypeParsed ($doctype);

  /**
   * @param string $markup
   */
  function invalidMarkupParsed ($markup);

  /**
   * @param string $tagName
   */
  function openTagParsed ($tagName);

  /**
   * @param string $text
   */
  function textParsed ($text);

  /**
   * @param string $whiteSpace
   */
  function whiteSpaceParsed ($whiteSpace);

}
