'use strict';

/**
 * AriunetClub custom stylelint rules.
 *
 * Enforces the unified light palette contract:
 *  1. ariunetclub/no-gradient-functions        - gradients are banned everywhere.
 *  2. ariunetclub/no-raw-colors-outside-root   - raw HEX/rgb()/hsl() colors may
 *                                                only appear inside the :root token block.
 *  3. ariunetclub/no-low-contrast-text-colors  - accent (#60A5FA, 2.50:1 on the
 *                                                surface) and warning (#FAB561,
 *                                                1.74:1) must never be text colors.
 *  4. ariunetclub/tokens-only-on-root          - --ariunetclub-* custom
 *                                                properties may only be defined on :root.
 */

import stylelint from 'stylelint';

const { report } = stylelint.utils;

const GRADIENT_RE = /(?:linear|radial|conic|repeating-linear|repeating-radial|repeating-conic)-gradient\s*\(/i;

const RAW_COLOR_RE =
        /#[0-9a-fA-F]{3,8}\b|(?:\brgba?\(|\bhsla?\(|\bhwb\(|\blab\(|\blch\(|\boklab\(|\boklch\(|\bcolor\s*\()/i;
const URL_RE = /url\([^)]*\)/gi;

const TEXT_COLOR_PROPS = new Set(['color', 'caret-color', '-webkit-text-fill-color', 'fill', 'stroke']);
const BANNED_TEXT_TOKENS = ['--ariunetclub-accent', '--ariunetclub-warning'];
const BANNED_TEXT_HEX = new Set(['#60a5fa', '#fab561']);

/** True when the declaration sits inside a rule whose selector is exactly ":root". */
const isInsideRoot = (node) => {
        let parent = node.parent;
        while (parent) {
                if (parent.type === 'rule' && parent.selector.split(',').some((s) => s.trim() === ':root')) {
                        return true;
                }
                parent = parent.parent;
        }
        return false;
};

/** Wrap a visitor into a stylelint rule definition object. */
const makeRule = (ruleName, visitor) => {
        const rule = () => (root, result) => visitor(root, result, ruleName);
        rule.ruleName = ruleName;
        rule.messages = {};
        return { ruleName, rule, messages: {} };
};

const noGradientFunctions = (root, result, ruleName) => {
        root.walkDecls((decl) => {
                if (GRADIENT_RE.test(decl.value)) {
                        report({
                                node: decl,
                                result,
                                ruleName,
                                message: 'Gradients are banned in AriunetClub styles (flat light design only)',
                        });
                }
        });
};

const noRawColorsOutsideRoot = (root, result, ruleName) => {
        root.walkDecls((decl) => {
                if (isInsideRoot(decl)) {
                        return;
                }
                const value = decl.value.replace(URL_RE, '');
                if (RAW_COLOR_RE.test(value)) {
                        report({
                                node: decl,
                                result,
                                ruleName,
                                message:
                                        'Raw color "' + value.trim() + '" outside :root - use an --ariunetclub-* token ' +
                                        '(raw colors live only in the :root palette block)',
                        });
                }
        });
};

const noLowContrastTextColors = (root, result, ruleName) => {
        root.walkDecls((decl) => {
                if (!TEXT_COLOR_PROPS.has(decl.prop.toLowerCase())) {
                        return;
                }
                const value = decl.value.toLowerCase();
                let banned = null;
                for (const token of BANNED_TEXT_TOKENS) {
                        if (value.includes('var(' + token + ')')) {
                                banned = 'var(' + token + ')';
                                break;
                        }
                }
                if (!banned) {
                        for (const hex of value.match(/#[0-9a-f]{3,8}\b/g) || []) {
                                if (BANNED_TEXT_HEX.has(hex)) {
                                        banned = hex;
                                        break;
                                }
                        }
                }
                if (banned) {
                        const reason = banned.includes('accent') || banned === '#60a5fa'
                                ? 'accent (#60A5FA) reaches only 2.50:1 as text on the surface'
                                : 'warning (#FAB561) reaches only 1.74:1 as text on the surface';
                        report({
                                node: decl,
                                result,
                                ruleName,
                                message:
                                        banned + ' must never be a text color - ' + reason +
                                        '; text on accent/warning backgrounds is always var(--ariunetclub-text)',
                        });
                }
        });
};

const tokensOnlyOnRoot = (root, result, ruleName) => {
        root.walkDecls((decl) => {
                if (!decl.prop.startsWith('--ariunetclub-')) {
                        return;
                }
                if (!isInsideRoot(decl)) {
                        report({
                                node: decl,
                                result,
                                ruleName,
                                message: 'Token ' + decl.prop + ' is defined outside :root - AriunetClub tokens live only on :root',
                        });
                }
        });
};

export default [
        makeRule('ariunetclub/no-gradient-functions', noGradientFunctions),
        makeRule('ariunetclub/no-raw-colors-outside-root', noRawColorsOutsideRoot),
        makeRule('ariunetclub/no-low-contrast-text-colors', noLowContrastTextColors),
        makeRule('ariunetclub/tokens-only-on-root', tokensOnlyOnRoot),
];
