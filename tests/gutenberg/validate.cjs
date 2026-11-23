/* Validate generated content against the same registered core save functions as WordPress. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { JSDOM } = require('jsdom');

async function main() {
    const input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
    const baseUrl = process.env.PIN_WORDPRESS_URL || 'http://localhost:8888';
    const dom = new JSDOM('<!doctype html><html><body></body></html>', {
        url: baseUrl, runScripts: 'outside-only', pretendToBeVisual: true,
    });
    const window = dom.window;
    window.matchMedia = () => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} });
    window.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
    window.TextEncoder = TextEncoder;
    window.TextDecoder = TextDecoder;
    // Parser diagnostics are collected below instead of dumping whole source documents.
    window.console = { ...console, warn() {}, error() {}, info() {}, log() {} };
    for (const dependency of input.dependencies) {
        let source;
        if (process.env.PIN_WORDPRESS_ROOT) {
            const file = path.resolve(process.env.PIN_WORDPRESS_ROOT, '.' + new URL(dependency.src, baseUrl).pathname);
            if (!file.startsWith(path.resolve(process.env.PIN_WORDPRESS_ROOT) + path.sep)) throw new Error('Unsafe dependency path');
            source = fs.readFileSync(file, 'utf8');
        } else {
            const response = await fetch(new URL(dependency.src, baseUrl));
            if (!response.ok) throw new Error(`WordPress dependency ${dependency.handle}: ${response.status}`);
            source = await response.text();
        }
        vm.runInContext(source, dom.getInternalVMContext(), { filename: dependency.handle + '.js' });
    }
    window.wp.blockLibrary.registerCoreBlocks();
    let count = 0;
    const invalid = [];
    function walk(blocks, route) {
        for (const block of blocks) {
            count++;
            if (block.isValid === false) invalid.push({ route, name: block.name, original: block.originalContent.slice(0,300) });
            walk(block.innerBlocks || [], route);
        }
    }
    for (const page of [...input.pages, ...input.parts, ...input.templates]) {
        walk(window.wp.blocks.parse(page.content), page.route);
    }
    console.log(JSON.stringify({ pages: input.pages.length, blocks: count, invalid }, null, 2));
    dom.window.close();
    if (invalid.length) process.exitCode = 1;
}
main().catch(error => { console.error(error); process.exitCode = 1; });
