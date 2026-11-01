// Site runtime. Loaded only on pages that need it; the script tag lists what the page uses:
//   <script type="module" src="…/cms-[hash].js" data-features="logic motion search code">
// Each feature is its own chunk, so a page with one animated heading never downloads Alpine.

const script = document.querySelector<HTMLScriptElement>('script[data-features]')
const features = new Set((script?.dataset.features ?? '').split(/\s+/).filter(Boolean))
const tasks: Promise<unknown>[] = []

if (features.has('motion')) tasks.push(import('./motion').then((m) => m.start()))
if (features.has('logic') || features.has('ajax')) tasks.push(import('./alpine').then((m) => m.start()))
if (features.has('code')) tasks.push(import('./code').then((m) => m.start()))
if (features.has('search')) tasks.push(import('./search').then((m) => m.start()))
if (features.has('tabs')) tasks.push(import('./tabs').then((m) => m.start()))
if (features.has('filter')) tasks.push(import('./filter').then((m) => m.start()))

Promise.all(tasks).catch((error) => console.error('[cms]', error))
