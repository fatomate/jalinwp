/* global wp */
(function () {
    'use strict';
    /** @type {Set<string>} */
    const allowed = new Set(['core/group', 'core/columns', 'core/column', 'core/heading', 'core/paragraph', 'core/image', 'core/cover', 'core/buttons', 'core/button', 'core/list', 'core/list-item', 'core/separator', 'core/spacer']);
    /** Run the installed editor's actual static block validator. Never render or execute proposal HTML.
     * @param {string} markup
     * @returns {FGBlockValidationResult}
     */
    function validate(markup) {
        /** @type {FGValidationError[]} */
        const errors = [];
        let count = 0;
        if (typeof markup !== 'string' || markup.length === 0 || new TextEncoder().encode(markup).length > 60000) {
            return { valid: false, blockCount: 0, errors: [{ path: 'blocks', message: 'Provide saved block markup of at most 60,000 bytes.' }] };
        }
        if (!window.wp || !wp.blocks || typeof wp.blocks.parse !== 'function' || typeof wp.blocks.validateBlock !== 'function') {
            return { valid: false, blockCount: 0, errors: [{ path: 'blocks', message: 'The WordPress block validator is unavailable. Reload this page before approving.' }] };
        }
        try {
            const nodes = wp.blocks.parse(markup);
            /**
             * @param {FGGutenbergBlock[]} blocks
             * @param {number} depth
             * @param {string} path
             */
            function visit(blocks, depth, path) {
                if (blocks.length === 0) return;
                if (depth > 8) { errors.push({ path, message: 'The maximum nesting depth is eight.' }); return; }
                blocks.forEach(function (block, i) {
                    const at = path + '[' + i + ']';
                    count++;
                    if (count > 100) { if (count === 101) errors.push({ path: at, message: 'The maximum block count is 100.' }); return; }
                    if (!allowed.has(block.name) || !wp.blocks.getBlockType(block.name)) {
                        errors.push({ path: at, message: 'No supported installed adapter for ' + String(block.name) + '.' }); return;
                    }
                    const result = wp.blocks.validateBlock(block);
                    if (!block.isValid || !result[0]) errors.push({ path: at, message: 'Saved markup does not match the installed ' + block.name + ' block. Regenerate this proposal.' });
                    visit(block.innerBlocks || [], depth + 1, at + '.innerBlocks');
                });
            }
            visit(nodes, 1, 'blocks');
            if (count === 0) errors.push({ path: 'blocks', message: 'No editable blocks were found.' });
        } catch (error) {
            errors.push({ path: 'blocks', message: 'WordPress could not validate this design. Regenerate the proposal.' });
        }
        return { valid: errors.length === 0, blockCount: count, errors };
    }
    window.JalinDesignValidation = Object.freeze({ validate });
}());
