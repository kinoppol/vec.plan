// core.jsx — utilities, API client and shared UI components (loaded first)
const { useState, useEffect, useMemo, useRef, useCallback, createContext, useContext, Fragment } = React;
const BOOT = window.__BOOT__ || {};

// ------------------------------------------------------------------ formatting
const TH_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const TH_MONTHS_FULL = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
const FISCAL_MONTHS = ['ต.ค.', 'พ.ย.', 'ธ.ค.', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.'];
const QUARTERS = { 1: 'ไตรมาส 1 (ต.ค.–ธ.ค.)', 2: 'ไตรมาส 2 (ม.ค.–มี.ค.)', 3: 'ไตรมาส 3 (เม.ย.–มิ.ย.)', 4: 'ไตรมาส 4 (ก.ค.–ก.ย.)' };

function fmt(n) {
  const v = Number(n) || 0;
  return (v < 0 ? '−' : '') + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtSigned(n) { return (n > 0 ? '+' : '') + fmt(n); }
function fmt0(n) { const v = Number(n) || 0; return (v < 0 ? '−' : '') + Math.abs(Math.round(v)).toLocaleString('en-US'); }
function fmtM(n) { return ((Number(n) || 0) / 1e6).toFixed(2); }
function pct(a, b) { return b ? Math.max(0, Math.min(100, a / b * 100)) : 0; }
function thDate(iso, withTime) {
  if (!iso) return '';
  const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (!m) return iso;
  const s = `${+m[3]} ${TH_MONTHS[+m[2] - 1]} ${+m[1] + 543}`;
  return withTime && m[4] ? `${s} ${m[4]}:${m[5]}` : s;
}
function quarterText(qs) {
  if (!qs || !qs.length) return '—';
  if (qs.length === 1) return 'ไตรมาส ' + qs[0];
  const sorted = [...qs].sort();
  const contiguous = sorted.every((q, i) => i === 0 || q === sorted[i - 1] + 1);
  return 'ไตรมาส ' + (contiguous ? sorted[0] + '–' + sorted[sorted.length - 1] : sorted.join(', '));
}
function initials(name) {
  const parts = String(name || '').replace(/^(นาย|นางสาว|นาง|ครู|ดร\.)/, '').trim().split(/\s+/);
  return (parts[0] || '').slice(0, 1) + (parts[1] || '').slice(0, 1);
}
/** Parse a money string the user typed ("1,234.5") → number or NaN. */
function parseMoney(s) {
  if (typeof s === 'number') return s;
  const t = String(s || '').replace(/[,\s฿]/g, '');
  if (t === '') return NaN;
  return /^-?\d*\.?\d*$/.test(t) ? Number(t) : NaN;
}

// ------------------------------------------------------------------ status palettes (design-brief §6)
const PROJECT_STATUS = {
  draft: ['ร่าง', '#EDEFF3', '#465166'], submitted: ['อยู่ระหว่างเสนอ', '#E5EFFD', '#1C59B5'], returned: ['ส่งกลับแก้ไข', '#FDECDF', '#A84A0C'],
  planning_review: ['งานแผนฯ ตรวจ', '#E5EFFD', '#1C59B5'], pending_decision: ['รอพิจารณา', '#EEE8FA', '#5A3EA6'], waitlisted: ['รอเงินเพิ่ม', '#FBF1CF', '#7E5A00'],
  rejected: ['ไม่อนุมัติ', '#DFE3E9', '#2C3546'], approved: ['อนุมัติในแผน', '#DAE6FA', '#16469A'], in_progress: ['กำลังดำเนินการ', '#0F2C5C', '#FFFFFF'],
  awaiting_report: ['รอรายงานผล', '#FDECDF', '#A84A0C'], closed: ['ปิดโครงการ', '#D9F0E2', '#13693A'], cancelled: ['ยกเลิก', '#DFE3E9', '#2C3546'],
  transferred_out: ['โอนออกทั้งหมด', '#EDEFF3', '#465166'],
};
const HEALTH = { green: ['ตามแผน', '#1F9D55'], yellow: ['ล่าช้า', '#D9A406'], red: ['เสี่ยง', '#D23C3C'] };
const LEDGER_TYPE_TONE = {
  carry_in: ['#EDEFF3', '#465166'], receipt: ['#D9F0E2', '#13693A'], allocation_adjust_up: ['#D9F0E2', '#13693A'], allocation_adjust_down: ['#FDECDF', '#A84A0C'],
  reserve: ['#FBF1CF', '#7E5A00'], allocate: ['#EEE8FA', '#5A3EA6'], transfer: ['#FDECDF', '#A84A0C'], spend: ['#DAE6FA', '#16469A'],
  return: ['#E5EFFD', '#1C59B5'], carry_out: ['#EDEFF3', '#465166'], reversal: ['#EDEFF3', '#465166'],
};
const FUND_TYPE_LABEL = { state_budget: 'เงินงบประมาณ', subsidy: 'เงินอุดหนุน', institution_income: 'เงินรายได้สถานศึกษา', donation: 'เงินบริจาค', other: 'อื่น ๆ' };
const PERMIT_RULE_LABEL = {
  allocation_letter: 'มีหนังสือแจ้งจัดสรร/รับเงินแล้ว', cumulative_receipts: 'ไม่เกินรับจริงสะสม', cash_available: 'ไม่เกินเงินสดคงเหลือ',
};

// ------------------------------------------------------------------ API client
class ApiErr extends Error {
  constructor(message, status, data) { super(message); this.status = status; this.data = data || {}; }
}
const apiState = { fy: null, onUnauthorized: null };
function apiUrl(path, query) {
  const q = new URLSearchParams();
  q.set('r', path);
  if (apiState.fy && !(query && 'fy' in query)) q.set('fy', apiState.fy);
  Object.entries(query || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.set(k, v); });
  return BOOT.base + 'api/?' + q.toString();
}
async function api(path, { method = 'GET', body, query, form } = {}) {
  const opts = { method, headers: { 'X-CSRF-Token': BOOT.csrf, Accept: 'application/json' }, credentials: 'same-origin' };
  if (form) opts.body = form;
  else if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  let res;
  try { res = await fetch(apiUrl(path, query), opts); }
  catch (e) { throw new ApiErr('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ตรวจสอบเครือข่ายแล้วลองอีกครั้ง', 0); }
  let data = null;
  try { data = await res.json(); } catch (e) { /* non-JSON */ }
  if (!res.ok) {
    if (res.status === 401 && apiState.onUnauthorized) apiState.onUnauthorized();
    throw new ApiErr((data && data.error) || ('เกิดข้อผิดพลาด (HTTP ' + res.status + ')'), res.status, data);
  }
  return data;
}
const post = (path, body, query) => api(path, { method: 'POST', body: body || {}, query });
function download(path, query) { window.location.href = apiUrl(path, query); }

/** Load data from a GET endpoint; reloads when deps change. */
function useApi(path, query, deps) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const [tick, setTick] = useState(0);
  const key = JSON.stringify([path, query, apiState.fy]);
  useEffect(() => {
    let alive = true;
    setState(s => ({ ...s, loading: true, error: null }));
    api(path, { query }).then(
      data => alive && setState({ data, error: null, loading: false }),
      error => alive && setState({ data: null, error, loading: false }),
    );
    return () => { alive = false; };
  }, [key, tick].concat(deps || []));
  return { ...state, reload: () => setTick(t => t + 1) };
}

// ------------------------------------------------------------------ app context
const AppCtx = createContext(null);
const useApp = () => useContext(AppCtx);

/** Fund tree helpers built from meta.funds. */
function buildFunds(list) {
  const byId = {};
  list.forEach(f => { byId[f.id] = { ...f, id: +f.id, parent_id: f.parent_id ? +f.parent_id : null, is_leaf: !!+f.is_leaf, active: !!+f.active, children: [] }; });
  Object.values(byId).forEach(f => { if (f.parent_id && byId[f.parent_id]) byId[f.parent_id].children.push(f); });
  const roots = Object.values(byId).filter(f => !f.parent_id);
  const path = id => { const out = []; for (let f = byId[id]; f; f = byId[f.parent_id]) out.unshift(f); return out; };
  const label = id => path(id).map(f => f.name).join(' › ');
  const colorOf = id => { for (let f = byId[id]; f; f = byId[f.parent_id]) if (f.color) return f.color; return '#8A96A8'; };
  // Leaves in tree order (depth-first by sort), matching the fund tree everywhere.
  const order = [];
  const walk = f => { order.push(f); f.children.sort((a, b) => a.sort - b.sort || a.id - b.id).forEach(walk); };
  roots.sort((a, b) => a.sort - b.sort || a.id - b.id).forEach(walk);
  const leaves = order.filter(f => f.is_leaf);
  return { byId, roots, leaves, label, colorOf, path };
}
function buildUnits(list) {
  const byId = {};
  list.forEach(u => { byId[u.id] = { ...u, id: +u.id, parent_id: u.parent_id ? +u.parent_id : null, children: [] }; });
  Object.values(byId).forEach(u => { if (u.parent_id && byId[u.parent_id]) byId[u.parent_id].children.push(u); });
  const roots = Object.values(byId).filter(u => !u.parent_id);
  const flat = [];
  const walk = (u, depth) => { flat.push({ ...u, depth }); u.children.sort((a, b) => a.sort - b.sort || a.id - b.id).forEach(c => walk(c, depth + 1)); };
  roots.sort((a, b) => a.sort - b.sort || a.id - b.id).forEach(r => walk(r, 0));
  return { byId, roots, flat };
}

// ------------------------------------------------------------------ icons (Lucide-style paths from the design)
const ICONS = {
  home: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  inbox: 'M22 12h-6l-2 3h-4l-2-3H2M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z',
  list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
  filePlus: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M12 18v-6M9 15h6',
  shuffle: 'M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5',
  sliders: 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
  check: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  wallet: 'M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4',
  trend: 'M22 7l-8.5 8.5-5-5L2 17M16 7h6v6',
  download: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
  upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
  book: 'M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20',
  receipt: 'M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1zM8 8h8M8 12h8M8 16h5',
  pen: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  bar: 'M3 3v18h18M18 17V9M13 17V5M8 17v-3',
  gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z',
  menu: 'M3 6h18M3 12h18M3 18h18',
  search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
  bell: 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0',
  warn: 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01',
  info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 8v4M12 16h.01',
  ok: 'M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4 12 14l-3-3',
  x: 'M18 6 6 18M6 6l12 12',
  users: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
  db: 'M12 8c4.97 0 9-1.34 9-3s-4.03-3-9-3-9 1.34-9 3 4.03 3 9 3zM21 12c0 1.66-4 3-9 3s-9-1.34-9-3M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5',
  archive: 'M21 8v13H3V8M1 3h22v5H1zM10 12h4',
  history: 'M3 3v5h5M3.05 13A9 9 0 1 0 6 5.3L3 8M12 7v5l4 2',
  undo: 'M9 14 4 9l5-5M4 9h10.5a5.5 5.5 0 0 1 0 11H11',
  chevron: 'M9 6l6 6-6 6',
  moon: 'M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z',
  sun: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42',
  lock: 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4',
  paperclip: 'M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48',
  play: 'M5 3l14 9-14 9z',
  plus: 'M12 5v14M5 12h14',
};
function Icon({ name, size = 18, stroke = 1.75, style }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden="true"
      style={{ fill: 'none', stroke: 'currentColor', strokeWidth: stroke, strokeLinecap: 'round', strokeLinejoin: 'round', flexShrink: 0, ...style }}>
      <path d={ICONS[name] || ''} />
    </svg>
  );
}

// ------------------------------------------------------------------ small UI pieces
function Badge({ bg, fg, children, title, style }) {
  return <span className="badge" title={title} style={{ background: bg, color: fg, ...style }}>{children}</span>;
}
function StatusBadge({ status }) {
  const s = PROJECT_STATUS[status] || [status, '#EDEFF3', '#465166'];
  return <Badge bg={s[1]} fg={s[2]}>{s[0]}</Badge>;
}
function HealthDot({ health }) {
  if (!health || !HEALTH[health]) return <span className="row sm muted"><span className="dot" style={{ background: '#C5CCD6' }} />—</span>;
  return <span className="row sm" style={{ gap: 6, color: 'var(--text-3)' }}><span className="dot" style={{ background: HEALTH[health][1] }} />{HEALTH[health][0]}</span>;
}
function TypeBadge({ type, label }) {
  const t = LEDGER_TYPE_TONE[type] || ['#EDEFF3', '#465166'];
  return <Badge bg={t[0]} fg={t[1]} style={{ fontSize: 11.5, height: 21, padding: '0 7px' }}>{label}</Badge>;
}
function FundTag({ color, children }) {
  return <span className="row" style={{ gap: 6, display: 'inline-flex' }}><span className="sw" style={{ width: 9, height: 9, borderRadius: 2, background: color }} />{children}</span>;
}
function Alert({ tone = 'blue', icon, title, children, action }) {
  const ic = icon || (tone === 'red' ? 'warn' : tone === 'green' ? 'ok' : 'info');
  return (
    <div className={'alert ' + tone} role={tone === 'red' ? 'alert' : undefined}>
      <Icon name={ic} size={20} stroke={1.9} />
      <div className="grow">{title && <b>{title}</b>}{title && children ? ' — ' : ''}{children}</div>
      {action}
    </div>
  );
}
function Empty({ icon = 'filePlus', title, children, actions }) {
  return (
    <div className="empty">
      <div className="ic"><Icon name={icon} size={24} /></div>
      <div className="t">{title}</div>
      {children && <div className="d">{children}</div>}
      {actions && <div className="actions" style={{ marginTop: 6 }}>{actions}</div>}
    </div>
  );
}
function Skeleton({ rows = 4 }) {
  return (
    <div className="stack" aria-busy="true" aria-label="กำลังโหลด">
      <div className="grid-kpi">
        {[1, 2, 3, 4].map(i => (
          <div key={i} className="card kpi">
            <div className="skel" style={{ width: '40%', height: 12 }} /><div className="skel" style={{ width: '65%', height: 26 }} /><div className="skel" style={{ width: '85%', height: 10 }} />
          </div>
        ))}
      </div>
      <div className="card card-b stack">
        <div className="skel" style={{ width: 180, height: 14 }} />
        {Array.from({ length: rows }).map((_, i) => <div key={i} className="skel" style={{ height: 14 }} />)}
      </div>
    </div>
  );
}
function LoadError({ error, onRetry }) {
  return (
    <div className="card" style={{ padding: '16px 18px', display: 'flex', gap: 14, alignItems: 'center', borderColor: 'var(--red-line)', flexWrap: 'wrap' }}>
      <div style={{ width: 40, height: 40, borderRadius: '50%', background: 'var(--red-bg)', color: 'var(--red-text)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <Icon name="x" size={20} stroke={1.9} />
      </div>
      <div className="grow">
        <div style={{ fontWeight: 600, color: 'var(--red-fg)' }}>โหลดข้อมูลไม่สำเร็จ</div>
        <div className="sm muted" style={{ marginTop: 2 }}>{error && error.message}</div>
      </div>
      {onRetry && <button className="btn primary" onClick={onRetry}>ลองอีกครั้ง</button>}
    </div>
  );
}
function PageHead({ crumb, title, children }) {
  return (
    <div className="page-head">
      <div>
        {crumb && <div className="crumb">{crumb}</div>}
        <h1 className="title">{title}</h1>
      </div>
      {children && <div className="actions">{children}</div>}
    </div>
  );
}
function Field({ label, required, hint, error, children, style }) {
  return (
    <label className="field" style={style}>
      <span>{label}{required && <span className="req"> *</span>}</span>
      {children}
      {error ? <span className="error">{error}</span> : hint ? <span className="hint">{hint}</span> : null}
    </label>
  );
}
function MoneyInput({ value, onChange, invalid, ...rest }) {
  return (
    <input className={'input num' + (invalid ? ' err' : '')} inputMode="decimal" autoComplete="off" value={value}
      onChange={e => onChange(e.target.value)}
      onBlur={e => { const n = parseMoney(e.target.value); if (!isNaN(n)) onChange(n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })); }}
      {...rest} />
  );
}
/** Date entry in the Buddhist era (spec §12): day / month / year B.E. → ISO yyyy-mm-dd */
function ThaiDateInput({ value, onChange, minYear, maxYear, invalid }) {
  const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
  const y = m ? +m[1] + 543 : '', mo = m ? +m[2] : '', d = m ? +m[3] : '';
  const nowBe = new Date().getFullYear() + 543;
  const years = []; for (let i = (maxYear || nowBe + 1); i >= (minYear || nowBe - 3); i--) years.push(i);
  const set = (nd, nm, ny) => {
    if (!nd || !nm || !ny) { onChange(''); return; }
    const ce = ny - 543;
    const last = new Date(ce, nm, 0).getDate();
    const dd = Math.min(nd, last);
    onChange(`${ce}-${String(nm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`);
  };
  const cls = 'select' + (invalid ? ' err' : '');
  return (
    <div className="row" style={{ gap: 6 }}>
      <select className={cls} aria-label="วัน" value={d} onChange={e => set(+e.target.value, mo || 1, y || nowBe)} style={{ width: 66 }}>
        <option value="">วัน</option>{Array.from({ length: 31 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
      </select>
      <select className={cls} aria-label="เดือน" value={mo} onChange={e => set(d || 1, +e.target.value, y || nowBe)} style={{ flex: 1, minWidth: 96 }}>
        <option value="">เดือน</option>{TH_MONTHS_FULL.map((n, i) => <option key={i} value={i + 1}>{n}</option>)}
      </select>
      <select className={cls} aria-label="ปี พ.ศ." value={y} onChange={e => set(d || 1, mo || 1, +e.target.value)} style={{ width: 84 }}>
        <option value="">พ.ศ.</option>{years.map(v => <option key={v} value={v}>{v}</option>)}
      </select>
    </div>
  );
}
function Modal({ title, onClose, children, footer, wide }) {
  useEffect(() => {
    const k = e => { if (e.key === 'Escape') onClose && onClose(); };
    window.addEventListener('keydown', k);
    return () => window.removeEventListener('keydown', k);
  }, [onClose]);
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={title}>
      <div className="backdrop" onClick={onClose} />
      <div className={'box' + (wide ? ' wide' : '')}>
        <div className="mh"><h3>{title}</h3><button className="icon-btn plain" onClick={onClose} title="ปิด (Esc)"><Icon name="x" size={16} /></button></div>
        <div className="mb">{children}</div>
        {footer && <div className="mf">{footer}</div>}
      </div>
    </div>
  );
}
function useBusy() {
  const [busy, setBusy] = useState(false);
  const run = async fn => { if (busy) return; setBusy(true); try { return await fn(); } finally { setBusy(false); } };
  return [busy, run];
}
function Pager({ page, per, total, onPage }) {
  const pages = Math.max(1, Math.ceil(total / per));
  if (pages <= 1) return null;
  const list = [];
  for (let i = 1; i <= pages; i++) if (i === 1 || i === pages || Math.abs(i - page) <= 2) list.push(i); else if (list[list.length - 1] !== '…') list.push('…');
  return (
    <div className="row" style={{ gap: 4 }}>
      {list.map((p, i) => p === '…' ? <span key={'e' + i} style={{ padding: '0 6px' }}>…</span> :
        <button key={p} className="btn sm" onClick={() => onPage(p)}
          style={p === page ? { background: 'var(--navy)', color: 'var(--on-navy)', borderColor: 'var(--navy)', width: 30 } : { width: 30 }}>{p}</button>)}
    </div>
  );
}

Object.assign(window, {
  useState, useEffect, useMemo, useRef, useCallback, createContext, useContext, Fragment, BOOT,
  TH_MONTHS, FISCAL_MONTHS, QUARTERS, fmt, fmtSigned, fmt0, fmtM, pct, thDate, quarterText, initials, parseMoney,
  PROJECT_STATUS, HEALTH, LEDGER_TYPE_TONE, FUND_TYPE_LABEL, PERMIT_RULE_LABEL,
  ApiErr, apiState, apiUrl, api, post, download, useApi, AppCtx, useApp, buildFunds, buildUnits,
  Icon, Badge, StatusBadge, HealthDot, TypeBadge, FundTag, Alert, Empty, Skeleton, LoadError, PageHead, Field, MoneyInput,
  ThaiDateInput, Modal, useBusy, Pager,
});
