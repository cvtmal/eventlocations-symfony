// Prints one line per Lighthouse category (score 0–100) plus the report paths. No threshold (PD-13).
import {readFileSync} from 'node:fs';

const jsonPath = process.argv[2];
const report = JSON.parse(readFileSync(jsonPath, 'utf8'));

process.stdout.write(`Lighthouse ${report.lighthouseVersion} — ${report.finalDisplayedUrl ?? report.finalUrl}\n`);
for (const id of ['performance', 'accessibility', 'best-practices', 'seo']) {
    const category = report.categories[id];
    const score = category && category.score !== null ? Math.round(category.score * 100) : 'n/a';
    process.stdout.write(`  ${id.padEnd(15)} ${score}\n`);
}
const local = (path) => path.replace(/^\/reports\//, 'var/lighthouse/');
process.stdout.write(`  report: ${local(jsonPath)}\n  report: ${local(jsonPath.replace(/\.json$/, '.html'))}\n`);
