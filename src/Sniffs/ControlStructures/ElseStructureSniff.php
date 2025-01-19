<?php

namespace bdk\Sniffs\ControlStructures;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Disallow else
 *
 * An if expression with an else branch is basically not necessary.
 * You can rewrite the conditions in a way that the code becomes simpler to read.
 * To achieve this, use early return statements, though
 * you may need to split the code in several smaller methods.
 * For very simple assignments you could also use the ternary operations.
 */
class ElseStructureSniff implements Sniff
{
    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register()
    {
        return [
            T_ELSE,
        ];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current token in the
     *                                               stack passed in $tokens.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        // Ignore the ELSE in ELSE IF
        if ($tokens[$stackPtr]['code'] === T_ELSE) {
            $next = $phpcsFile->findNext(T_WHITESPACE, $stackPtr + 1, null, true);
            if ($tokens[$next]['code'] === T_IF) {
                return;
            }
        }

        $phpcsFile->addError('else is not allowed', $stackPtr, 'NotAllowed');
    }
}
