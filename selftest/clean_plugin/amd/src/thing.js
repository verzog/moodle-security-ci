/**
 * Thing.
 * @module local_clean/thing
 */
export const init = (el, text) => {
    el.textContent = text;
    return M.cfg.wwwroot + '/local/clean/index.php?sesskey=' + M.cfg.sesskey;
};
