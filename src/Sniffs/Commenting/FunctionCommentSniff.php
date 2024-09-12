<?php

/**
 * Parses and verifies the doc comments for functions.
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @copyright 2006-2015 Squiz Pty Ltd (ABN 77 084 670 600)
 * @license   https://github.com/squizlabs/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace bdk\Sniffs\Commenting;

use bdk\Sniffs\Commenting\Common;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Standards\Squiz\Sniffs\Commenting\FunctionCommentSniff as SquizFunctionCommentSniff;

/**
 * Extends Squiz FunctionCommentSniff
 *  * exclude comments consisting solely of "{@inheritDoc}"
 *  * require @param/@return types of int/bool vs integer/boolean
 */
class FunctionCommentSniff extends SquizFunctionCommentSniff
{
    /**
     * The current PHP version.
     *
     * @var int
     */
    private $phpVersion = null;

    protected $isInheritDoc = false;

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  The position of the current token
     *                           in the stack passed in $tokens.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $this->isInheritDoc = $this->isInheritDoc($phpcsFile, $stackPtr);
        parent::process($phpcsFile, $stackPtr);
    }

    /**
     * Process the function parameter comments.
     *
     * @param File $phpcsFile    The file being scanned.
     * @param int  $stackPtr     The position of the current token
     *                              in the stack passed in $tokens.
     * @param int  $commentStart The position in the stack where the comment started.
     *
     * @return void
     */
    protected function processParams(File $phpcsFile, $stackPtr, $commentStart)
    {
        if ($this->isInheritDoc) {
            return;
        }
        if ($this->phpVersion === null) {
            $this->phpVersion = Config::getConfigData('php_version');
            if ($this->phpVersion === null) {
                $this->phpVersion = PHP_VERSION_ID;
            }
        }

        $tokens = $phpcsFile->getTokens();

        $haveMultiLineType = false;
        $params  = [];
        $maxType = 0;
        $maxVar  = 0;

        foreach ($tokens[$commentStart]['comment_tags'] as $pos => $tag) {
            if ($tokens[$tag]['content'] !== '@param') {
                continue;
            }

            $content = '';
            $tagTemp = $tag;
            while (true) {
                $tagTemp++;
                if (\in_array($tokens[$tagTemp]['code'], array(T_DOC_COMMENT_TAG, T_DOC_COMMENT_CLOSE_TAG), true)) {
                    break;
                }
                $content .= $tokens[$tagTemp]['content'];
            }
            // remove leading "*"s
            $content = \preg_replace('#^[ \t]*\*[ ]?#m', '', $content);
            $content = \trim($content);

            $type         = '';
            $typeSpace    = 0;
            $var          = '';
            $varSpace     = 0;
            $comment      = '';
            $commentLines = [];
            if ($tokens[$tag + 2]['code'] === T_DOC_COMMENT_STRING) {
                /*
                $matches = [];
                // 1 = type
                // 2 = name
                // 3 = space after name
                // 4 = comment / desc
                $regex = '/
                    ([^$&.]+)
                    (?:
                        (
                        (?:\.\.\.)?
                        (?:\$|&)[^\s]+
                        )
                        (?:(\s+)(.*))?
                    )?
                    /x';
                \preg_match($regex, $content, $matches);
                */

                $type = $this->extractTypeFromBody($content);
                if ($type === false) {
                    $error = 'Invalid param type';
                    $phpcsFile->addError($error, $tag, 'ParamTypeInvalid');
                }
                \preg_match('/^(' . \preg_quote($type, '/') . '\s+)(.*)$/s', $content, $matches);

                $type = $matches[1];
                $content = $matches[2];
                if (self::strStartsWithVariable($content)) {
                    \preg_match('/^(\S*\s*)(.*)/', $content, $matches);
                    $var = $matches[1];
                    $comment = $matches[2];
                }

                /*
                var_dump(array(
                    'type' => $type,
                    'var' => $var,
                    'comment' => $comment,
                ));
                */

                if (\strlen($type)) {
                    $typeLen   = \strlen($type);
                    $type      = \trim($type);
                    $typeSpace = $typeLen - \strlen($type);
                    $typeLen   = \strlen($type);
                    if ($typeLen > $maxType) {
                        $maxType = $typeLen;
                    }
                    if (\preg_match('/[\r\n]/', $type)) {
                        $haveMultiLineType = true;
                    }
                }

                if (\strlen($var)) {
                    $varLen   = \strlen($var);
                    $var      = \trim($matches[1]);
                    $varSpace = $varLen - \strlen($var);
                    $varLen   = \strlen($var);

                    if ($varLen > $maxVar) {
                        $maxVar = $varLen;
                    }

                    if (\strlen($comment)) {
                        $commentLines[] = [
                            'comment' => $comment,
                            'token'   => $tag + 2,
                            'indent'  => $varSpace,
                        ];

                        // Any strings until the next tag belong to this comment.
                        $end = isset($tokens[$commentStart]['comment_tags'][$pos + 1]) === true
                            ? $tokens[$commentStart]['comment_tags'][$pos + 1]
                            : $tokens[$commentStart]['comment_closer'];

                        for ($i = $tag + 3; $i < $end; $i++) {
                            if ($tokens[$i]['code'] === T_DOC_COMMENT_STRING) {
                                $indent = 0;
                                if ($tokens[$i - 1]['code'] === T_DOC_COMMENT_WHITESPACE) {
                                    $indent = $tokens[$i - 1]['length'];
                                }

                                $comment       .= ' ' . $tokens[$i]['content'];
                                $commentLines[] = [
                                    'comment' => $tokens[$i]['content'],
                                    'token'   => $i,
                                    'indent'  => $indent,
                                ];
                            }
                        }
                    } else {
                        $error = 'Missing parameter comment';
                        $phpcsFile->addError($error, $tag, 'MissingParamComment');
                        $commentLines[] = ['comment' => ''];
                    }//end if
                } else {
                    $error = 'Missing parameter name';
                    $phpcsFile->addError($error, $tag, 'MissingParamName');
                }//end if
            } else {
                $error = 'Missing parameter type';
                $phpcsFile->addError($error, $tag, 'MissingParamType');
            }//end if

            $params[] = [
                'tag'          => $tag,
                'type'         => $type,
                'var'          => $var,
                'comment'      => $comment,
                'commentLines' => $commentLines,
                'type_space'   => $typeSpace,
                'var_space'    => $varSpace,
            ];
        }//end foreach

        $realParams  = $phpcsFile->getMethodParameters($stackPtr);
        $foundParams = [];
        $variadicParams = [];

        // var_dump($params);
        // var_dump($realParams);

        // We want to use ... for all variable length arguments, so added
        // this prefix to the variable name so comparisons are easier.
        foreach ($realParams as $pos => $param) {
            if ($param['variable_length'] === true) {
                $realParams[$pos]['name'] = '...' . $realParams[$pos]['name'];
            }
        }

        foreach ($params as $pos => $param) {
            // If the type is empty, the whole line is empty.
            if ($param['type'] === '') {
                continue;
            }

            $param['varNorm'] = $param['var'];
            if (\strpos($param['var'], '...') !== false) {
                $param['varNorm'] = \trim($param['var'], ',.');
                $variadicParams[] = $param['varNorm'];
            }

            // Check the param type value.
            $typeNames          = \explode('|', $param['type']);
            $suggestedTypeNames = [];

            foreach ($typeNames as $typeName) {
                // Strip nullable operator.
                if ($typeName === '') {
                    $error = $var . ' @param\'s suggested types has "|" with missing type';
                    $phpcsFile->addError($error, $tag, 'MissingType');
                }
                if ($typeName[0] === '?') {
                    $typeName = \substr($typeName, 1);
                }

                $suggestedName        = Common::suggestType($typeName);
                $suggestedTypeNames[] = $suggestedName;

                if (\count($typeNames) > 1) {
                    continue;
                }

                // Check type hint for array and custom type.
                $suggestedTypeHint = '';
                if (\strpos($suggestedName, 'array') !== false || \strpos($suggestedName, 'list') !== false || \substr($suggestedName, -2) === '[]') {
                    $suggestedTypeHint = 'array';
                } elseif (\strpos($suggestedName, 'callable') !== false) {
                    $suggestedTypeHint = 'callable';
                } elseif (\strpos($suggestedName, 'callback') !== false) {
                    $suggestedTypeHint = 'callable';
                } elseif (\in_array($suggestedName, Common::$allowedTypes, true) === false) {
                    $suggestedTypeHint = $suggestedName;
                }

                if ($this->phpVersion >= 70000) {
                    if ($suggestedName === 'string') {
                        $suggestedTypeHint = 'string';
                    } elseif ($suggestedName === 'int' || $suggestedName === 'integer') {
                        $suggestedTypeHint = 'int';
                    } elseif ($suggestedName === 'float') {
                        $suggestedTypeHint = 'float';
                    } elseif ($suggestedName === 'bool' || $suggestedName === 'boolean') {
                        $suggestedTypeHint = 'bool';
                    }
                }

                if ($this->phpVersion >= 70200) {
                    if ($suggestedName === 'object') {
                        $suggestedTypeHint = 'object';
                    }
                }

                if ($suggestedTypeHint !== '' && isset($realParams[$pos]) === true) {
                    $typeHint = $realParams[$pos]['type_hint'];

                    // Remove namespace prefixes when comparing.
                    $compareTypeHint = \substr($suggestedTypeHint, (\strlen($typeHint) * -1));

                    if ($typeHint === '') {
                        $error = 'Type hint "%s" missing for %s';
                        $data  = [
                            $suggestedTypeHint,
                            $param['var'],
                        ];

                        $errorCode = 'TypeHintMissing';
                        if (
                            $suggestedTypeHint === 'string'
                            || $suggestedTypeHint === 'int'
                            || $suggestedTypeHint === 'float'
                            || $suggestedTypeHint === 'bool'
                        ) {
                            $errorCode = 'Scalar' . $errorCode;
                        }

                        $phpcsFile->addError($error, $stackPtr, $errorCode, $data);
                    } elseif ($typeHint !== $compareTypeHint && $typeHint !== '?' . $compareTypeHint) {
                        $error = 'Expected type hint "%s"; found "%s" for %s';
                        $data  = [
                            $suggestedTypeHint,
                            $typeHint,
                            $param['var'],
                        ];
                        $phpcsFile->addError($error, $stackPtr, 'IncorrectTypeHint', $data);
                    }//end if
                } elseif ($suggestedTypeHint === '' && isset($realParams[$pos]) === true) {
                    $typeHint = $realParams[$pos]['type_hint'];
                    if ($typeHint !== '') {
                        $error = 'Unknown type hint "%s" found for %s';
                        $data  = [
                            $typeHint,
                            $param['var'],
                        ];
                        $phpcsFile->addError($error, $stackPtr, 'InvalidTypeHint', $data);
                    }
                }//end if
            }//end foreach

            $suggestedType = \implode('|', $suggestedTypeNames);
            if ($param['type'] !== $suggestedType) {
                $error = 'Expected "%s" but found "%s" for parameter type';
                $data  = [
                    $suggestedType,
                    $param['type'],
                ];

                $fix = $phpcsFile->addFixableError($error, $param['tag'], 'IncorrectParamVarName', $data);
                if ($fix === true) {
                    $phpcsFile->fixer->beginChangeset();

                    $content  = $suggestedType;
                    $content .= \str_repeat(' ', $param['type_space']);
                    $content .= $param['var'];
                    $content .= \str_repeat(' ', $param['var_space']);
                    if (isset($param['commentLines'][0]) === true) {
                        $content .= $param['commentLines'][0]['comment'];
                    }

                    $phpcsFile->fixer->replaceToken($param['tag'] + 2, $content);

                    // Fix up the indent of additional comment lines.
                    foreach ($param['commentLines'] as $lineNum => $line) {
                        if (
                            $lineNum === 0
                            || $param['commentLines'][$lineNum]['indent'] === 0
                        ) {
                            continue;
                        }

                        $diff      = \strlen($param['type']) - \strlen($suggestedType);
                        $newIndent = $param['commentLines'][$lineNum]['indent'] - $diff;
                        $phpcsFile->fixer->replaceToken(
                            $param['commentLines'][$lineNum]['token'] - 1,
                            \str_repeat(' ', $newIndent)
                        );
                    }

                    $phpcsFile->fixer->endChangeset();
                }//end if
            }//end if

            if ($param['var'] === '') {
                continue;
            }

            $foundParams[] = $param['varNorm'];

            // Check number of spaces after the type.
            if ($haveMultiLineType === false) {
                $this->checkSpacingAfterParamType($phpcsFile, $param, $maxType);
            }

            // Make sure the param name is correct.
            if (isset($realParams[$pos]) === true) {
                $realName = $realParams[$pos]['name'];
                if ($realName !== $param['varNorm'] && !empty($param['variable_length']) && !empty($variadicParams)) {
                    $code = 'ParamNameNoMatch';
                    $data = [
                        $param['var'],
                        $realName,
                    ];

                    $error = 'Doc comment for parameter %s does not match ';
                    if (\strtolower($param['varNorm']) === \strtolower($realName)) {
                        $error .= 'case of ';
                        $code   = 'ParamNameNoCaseMatch';
                    }

                    $error .= 'actual variable name %s';

                    $phpcsFile->addError($error, $param['tag'], $code, $data);
                }
            } elseif (\strpos($param['var'], '...') === false) {
                // We must have an extra parameter comment.
                $error = 'Superfluous parameter comment';
                $phpcsFile->addError($error, $param['tag'], 'ExtraParamComment');
            }//end if

            if ($param['comment'] === '') {
                continue;
            }

            // Check number of spaces after the var name.
            if ($haveMultiLineType === false) {
                $this->checkSpacingAfterParamName($phpcsFile, $param, $maxVar);
            }

            // Param comments must start with a capital letter and end with a full stop.
            if (\preg_match('/^(\p{Ll}|\P{L})/u', $param['comment']) === 1) {
                $error = 'Parameter comment must start with a capital letter';
                $phpcsFile->addError($error, $param['tag'], 'ParamCommentNotCapital');
            }

            $lastChar = \substr($param['comment'], -1);
            if ($lastChar !== '.') {
                $error = 'Parameter comment must end with a full stop';
                $phpcsFile->addError($error, $param['tag'], 'ParamCommentFullStop');
            }
        }//end foreach

        $realNames = [];
        foreach ($realParams as $realParam) {
            $realNames[] = $realParam['name'];
        }

        // Report missing comments.
        $diff = \array_diff($realNames, $foundParams);
        if ($variadicParams && \end($realNames) == \end($diff)) {
            // last name doesn't match... but phpdoc indicates variadic... ignore
            \array_pop($diff);
        }
        foreach ($diff as $neededParam) {
            $error = 'Doc comment for parameter "%s" missing';
            $data  = [$neededParam];
            $phpcsFile->addError($error, $commentStart, 'MissingParamTag', $data);
        }
    }

    private static function extractTypeFromBody($tagStr)
    {
        $type = '';
        $nestingLevel = 0;
        for ($i = 0, $iMax = \strlen($tagStr); $i < $iMax; $i++) {
            $char = $tagStr[$i];
            if ($nestingLevel === 0 && \trim($char) === '') {
                break;
            }
            $type .= $char;
            if (\in_array($char, array('<', '(', '[', '{'), true)) {
                $nestingLevel++;
                continue;
            }
            if (\in_array($char, array('>', ')', ']', '}'), true)) {
                $nestingLevel--;
                continue;
            }
        }
        return $nestingLevel === 0
            ? $type
            : false;
    }

    /**
     * Test if string appears to start with a variable name
     *
     * @param string $str Stringto test
     *
     * @return bool
     */
    private static function strStartsWithVariable($str)
    {
        if ($str === null) {
            return false;
        }
        return \strpos($str, '$') === 0
           || \strpos($str, '&$') === 0
           || \strpos($str, '...$') === 0
           || \strpos($str, '&...$') === 0;
    }


    /**
     * Process the return comment of this function comment.
     *
     * @param File $phpcsFile    The file being scanned.
     * @param int  $stackPtr     The position of the current token
     *                              in the stack passed in $tokens.
     * @param int  $commentStart The position in the stack where the comment started.
     *
     * @return void
     */
    protected function processReturn(File $phpcsFile, $stackPtr, $commentStart)
    {
        if ($this->isInheritDoc) {
            return;
        }
        $tokens = $phpcsFile->getTokens();
        $return = null;

        foreach ($tokens[$commentStart]['comment_tags'] as $tag) {
            if ($tokens[$tag]['content'] === '@return') {
                if ($return !== null) {
                    $error = 'Only 1 @return tag is allowed in a function comment';
                    $phpcsFile->addError($error, $tag, 'DuplicateReturn');
                    return;
                }

                $return = $tag;
            }
        }

        // Skip constructor and destructor.
        $methodName      = $phpcsFile->getDeclarationName($stackPtr);
        $isSpecialMethod = ($methodName === '__construct' || $methodName === '__destruct');
        if ($isSpecialMethod === true) {
            return;
        }

        if ($return !== null) {
            $content = $tokens[$return + 2]['content'];
            if (empty($content) === true || $tokens[$return + 2]['code'] !== T_DOC_COMMENT_STRING) {
                $error = 'Return type missing for @return tag in function comment';
                $phpcsFile->addError($error, $return, 'MissingReturnType');
            } else {
                // Support both a return type and a description.
                \preg_match('`^((?:\|?(?:array\([^\)]*\)|[\\\\a-z0-9\[\]]+))*)( .*)?`i', $content, $returnParts);
                if (isset($returnParts[1]) === false) {
                    return;
                }

                $returnType = $returnParts[1];

                // Check return type (can be multiple, separated by '|').
                $typeNames      = \explode('|', $returnType);
                $suggestedNames = [];
                foreach ($typeNames as $typeName) {
                    $suggestedName = Common::suggestType($typeName);
                    if (\in_array($suggestedName, $suggestedNames, true) === false) {
                        $suggestedNames[] = $suggestedName;
                    }
                }

                $suggestedType = \implode('|', $suggestedNames);
                if ($returnType !== $suggestedType) {
                    $error = 'Expected "%s" but found "%s" for function return type';
                    $data  = [
                        $suggestedType,
                        $returnType,
                    ];
                    $fix   = $phpcsFile->addFixableError($error, $return, 'InvalidReturn', $data);
                    if ($fix === true) {
                        $replacement = $suggestedType;
                        if (empty($returnParts[2]) === false) {
                            $replacement .= $returnParts[2];
                        }

                        $phpcsFile->fixer->replaceToken($return + 2, $replacement);
                        unset($replacement);
                    }
                }

                // If the return type is void, make sure there is
                // no return statement in the function.
                if ($returnType === 'void') {
                    if (isset($tokens[$stackPtr]['scope_closer']) === true) {
                        $endToken = $tokens[$stackPtr]['scope_closer'];
                        for ($returnToken = $stackPtr; $returnToken < $endToken; $returnToken++) {
                            if (
                                $tokens[$returnToken]['code'] === T_CLOSURE
                                || $tokens[$returnToken]['code'] === T_ANON_CLASS
                            ) {
                                $returnToken = $tokens[$returnToken]['scope_closer'];
                                continue;
                            }

                            if (
                                $tokens[$returnToken]['code'] === T_RETURN
                                || $tokens[$returnToken]['code'] === T_YIELD
                                || $tokens[$returnToken]['code'] === T_YIELD_FROM
                            ) {
                                break;
                            }
                        }

                        if ($returnToken !== $endToken) {
                            // If the function is not returning anything, just
                            // exiting, then there is no problem.
                            $semicolon = $phpcsFile->findNext(T_WHITESPACE, $returnToken + 1, null, true);
                            if ($tokens[$semicolon]['code'] !== T_SEMICOLON) {
                                $error = 'Function return type is void, but function contains return statement';
                                $phpcsFile->addError($error, $return, 'InvalidReturnVoid');
                            }
                        }
                    }//end if
                } elseif ($returnType !== 'mixed' && \in_array('void', $typeNames, true) === false) {
                    // If return type is not void, there needs to be a return statement
                    // somewhere in the function that returns something.
                    if (isset($tokens[$stackPtr]['scope_closer']) === true) {
                        $endToken = $tokens[$stackPtr]['scope_closer'];
                        for ($returnToken = $stackPtr; $returnToken < $endToken; $returnToken++) {
                            if ($tokens[$returnToken]['code'] === T_CLOSURE
                                || $tokens[$returnToken]['code'] === T_ANON_CLASS
                            ) {
                                $returnToken = $tokens[$returnToken]['scope_closer'];
                                continue;
                            }

                            if (
                                $tokens[$returnToken]['code'] === T_RETURN
                                || $tokens[$returnToken]['code'] === T_YIELD
                                || $tokens[$returnToken]['code'] === T_YIELD_FROM
                            ) {
                                break;
                            }
                        }

                        if ($returnToken === $endToken) {
                            $error = 'Function return type is not void, but function has no return statement';
                            $phpcsFile->addError($error, $return, 'InvalidNoReturn');
                        } else {
                            $semicolon = $phpcsFile->findNext(T_WHITESPACE, $returnToken + 1, null, true);
                            if ($tokens[$semicolon]['code'] === T_SEMICOLON) {
                                $error = 'Function return type is not void, but function is returning void here';
                                $phpcsFile->addError($error, $returnToken, 'InvalidReturnNotVoid');
                            }
                        }
                    }//end if
                }//end if
            }//end if
        } else {
            $error = 'Missing @return tag in function comment';
            $phpcsFile->addError($error, $tokens[$commentStart]['comment_closer'], 'MissingReturn');
        }//end if
    }

    /**
     * Process any throw tags that this function comment has.
     *
     * @param File $phpcsFile    The file being scanned.
     * @param int  $stackPtr     The position of the current token
     *                              in the stack passed in $tokens.
     * @param int  $commentStart The position in the stack where the comment started.
     *
     * @return void
     */
    protected function processThrows(File $phpcsFile, $stackPtr, $commentStart)
    {
        if ($this->isInheritDoc) {
            return;
        }
        parent::processThrows($phpcsFile, $stackPtr, $commentStart);
    }

    /**
     * Does comment only contain "{@inheritdoc}" ?
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  The position of the current token
     *
     * @return bool
     */
    private function isInheritDoc(File $phpcsFile, $stackPtr)
    {
        // will this potentially find doc comment belonging to a different method?
        $phpDocStart = $phpcsFile->findPrevious(T_DOC_COMMENT_OPEN_TAG, $stackPtr);
        if ($phpDocStart === false) {
            // there is no doc comment
            return true;
        }
        $tokens = $phpcsFile->getTokens();
        $phpDocClose = $tokens[$phpDocStart]['comment_closer'];
        $nextPtr = $phpDocClose;
        while (true) {
            $nextPtr = $phpcsFile->findNext([T_WHITESPACE], $nextPtr + 1, null, true);
            $nextToken = $tokens[$nextPtr];
            if ($nextToken['code'] === T_ATTRIBUTE) {
                // skip over attribute
                $nextPtr = $nextToken['attribute_closer'];
                continue;
            }
            break;
        }

        if ($tokens[$nextPtr]['line'] !== $tokens[$stackPtr]['line']) {
            // DocComment we found doesn't belong to the function
            return true;
        }

        // $end = $phpcsFile->findNext(T_DOC_COMMENT_CLOSE_TAG, $phpDocStart);
        $content = $phpcsFile->getTokensAsString($phpDocStart + 1, $phpDocClose - $phpDocStart - 1);
        // remove leading "*"s
        $content = \preg_replace('#^[ \t]*\*[ ]?#m', '', $content);
        $content = \trim($content);
        if (\preg_match('#^{@inheritdoc}$#i', $content) === 1) {
            // comment contains only "{@inheritdoc}"
            return true;
		}
        if (\preg_match('/\s*{@inheritdoc}\s*$/im', $content) !== 1) {
            // comment does not contain '{inheritdoc}' on own line
            return false;
        }
        // if we only contain tags other than @param, & @return, then consider it inherited
        $tags = \array_map(static function ($tagPtr) use ($tokens) {
            return $tokens[$tagPtr]['content'];
        }, $tokens[$phpDocStart]['comment_tags']);
        return empty(\array_intersect($tags, array('@param' , '@return')));
	}
}
