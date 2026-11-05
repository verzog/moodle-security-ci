/**
 * Thing.
 * @module local_fixture/thing
 */
export const go = (el, html, url) => {
    el.innerHTML = html; // EXPECT:S5-js-html
    el.innerHTML = '';
    el.textContent = html;
    const a = `<a href="${url}">x</a>`; // EXPECT:S5-js-href
    const b = `<a href="${safeUrl(url)}">x</a>`;
    el.setAttribute('href', url); // EXPECT:S5-js-href
    // Security-lint: ignore S5-js-html (value comes from sanitizeRich()).
    el.insertAdjacentHTML('beforeend', html);
    return [a, b];
};
export const load = () => {
    const s = document.createElement('script'); // EXPECT:S6-external-asset
    const u = 'https://other.example.com/hook?sesskey=' + M.cfg.sesskey; // EXPECT:S16-sesskey-leak
    const v = M.cfg.wwwroot + '/local/fixture/x.php?sesskey=' + M.cfg.sesskey;
    return [s, u, v];
};
