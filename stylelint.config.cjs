'use strict';

/* AriunetClub stylelint config: runs the four custom palette rules from
   tools/stylelint/plugin.mjs. Invoke with `npm run lint:css` (also part of
   `composer qa` via tools/qa.php). */
module.exports = {
  plugins: ['./tools/stylelint/plugin.mjs'],
  rules: {
    'ariunetclub/no-gradient-functions': true,
    'ariunetclub/no-raw-colors-outside-root': true,
    'ariunetclub/no-low-contrast-text-colors': true,
    'ariunetclub/tokens-only-on-root': true,
  },
};
