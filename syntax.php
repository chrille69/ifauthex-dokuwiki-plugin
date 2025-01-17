<?php
/**
 * DokuWiki Plugin ifauthex (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Pietro Saccardi <lizardm4@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

require_once(__DIR__ . '/lib/grammar.php');

class syntax_plugin_ifauthex extends DokuWiki_Syntax_Plugin
{
    public static $renderPermissionArr = [];

    /** @inheritDoc */
    public function getType()
    {
        return 'formatting';
    }

    /** @inheritDoc */
    function getAllowedTypes()
    {
        return array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs', 'baseonly');
    }

    /** @inheritDoc */
    function getPType()
    {
        return 'stack';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 195;
    }

    /** @inheritDoc */
    public function connectTo($mode)
    {
        $this->Lexer->addEntryPattern('<ifauth\b.*?>(?=.*?</ifauth>)', $mode, 'plugin_ifauthex');
    }

    /** @inheritDoc */
    public function postConnect()
    {
        $this->Lexer->addExitPattern('</ifauth>', 'plugin_ifauthex');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        global $conf;
        switch ($state) {
            case DOKU_LEXER_ENTER:
                $matches = null;
                preg_match('/^<ifauth\b(.*?)>$/', $match, $matches);
                if (is_array($matches) && count($matches) > 0) {
                    // The last group contains the expression.
                    // Can't already pre-parse because DokuWiki serializes the
                    // objects that are returned, but it doesn't know about our
                    // custom classes at this point.
                    return array($state, $matches[count($matches) - 1]);
                }
                return array($state, null);
            case DOKU_LEXER_MATCHED:
                break;
            case DOKU_LEXER_UNMATCHED:
                return array($state, $match);
            case DOKU_LEXER_EXIT:
                return array($state, null);
        }
        return false;
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        list($state, $exprOrMatch) = $data;

        // never cache
        $renderer->nocache();
        global $TOC;

        switch ($state) {
            case DOKU_LEXER_ENTER:
                if ($exprOrMatch === null) {
                    // something went wrong
                    return false;
                }
                try {
                    // check if current user should see the content
                    $exprOrMatch = auth_expr_parse($exprOrMatch);
                    $renderPermissionThisTag = (bool) $exprOrMatch->evaluate();
                    $shouldRenderOld = $this->renderPermissionAllTags();
                    array_push( self::$renderPermissionArr, $renderPermissionThisTag);
                    $shouldRenderNew = $this->renderPermissionAllTags();

                    // Become active if renderPermission changes from true to false
                    if ($shouldRenderOld && (!$shouldRenderNew)) {
                        // point the renderer's doc to something else, remembering the old one
                        $renderer->meta['ifauthex.originalDoc'] = &$renderer->doc;
                        $ignoredDoc = is_array($renderer->doc) ? [] : '';
                        $renderer->doc = &$ignoredDoc;

                        // do the same for the toc list
                        $ignoredToc = [];
                        $renderer->meta['ifauthex.originalToc'] = &$renderer->toc;
                        $renderer->toc = &$ignoredToc;
                        $TOC = $ignoredToc;

                    }
                } catch (Exception $e) {
                    // something went wrong parsing the expression
                    msg(hsc($e->getMessage()), -1);
                    return false;
                }
                break;
            case DOKU_LEXER_UNMATCHED:
                $renderer->cdata($exprOrMatch);
                break;
            case DOKU_LEXER_EXIT:
                // point the renderer's doc and toc back to the original
                $shouldRenderOld = $this->renderPermissionAllTags();
                array_pop(self::$renderPermissionArr);
                $shouldRenderNew = $this->renderPermissionAllTags();

                // Become active if renderPermission changes from false to true
                if( (!$shouldRenderOld) && $shouldRenderNew ) {
                    $renderer->doc = &$renderer->meta['ifauthex.originalDoc'];
                    $renderer->toc = &$renderer->meta['ifauthex.originalToc'];
                    $TOC = &$renderer->meta['ifauthex.originalToc'];
                }
                break;
        }

        return true;
    }

    private function renderPermissionAllTags()
    {
        foreach ( self::$renderPermissionArr as $do ) {
            if(!$do) {
                return false;
            }
        }
        return true;
    }
}
