// layout.jsx — router, login screen, sidebar, top bar, toasts

// ------------------------------------------------------------------ hash router: #/page?x=1
function parseHash() {
  const h = (location.hash || '#/').slice(1);
  const [path, qs] = h.split('?');
  const params = {};
  new URLSearchParams(qs || '').forEach((v, k) => { params[k] = v; });
  return { page: (path || '/').replace(/^\//, '') || 'dashboard', params };
}
function useRoute() {
  const [route, setRoute] = useState(parseHash());
  useEffect(() => {
    const f = () => { setRoute(parseHash()); window.scrollTo(0, 0); };
    window.addEventListener('hashchange', f);
    return () => window.removeEventListener('hashchange', f);
  }, []);
  return route;
}
function navigate(page, params) {
  const qs = new URLSearchParams();
  Object.entries(params || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') qs.set(k, v); });
  const s = qs.toString();
  location.hash = '#/' + page + (s ? '?' + s : '');
}

// ------------------------------------------------------------------ toasts
function useToasts() {
  const [items, setItems] = useState([]);
  const push = useCallback((text, tone) => {
    const id = Math.random().toString(36).slice(2);
    setItems(xs => [...xs, { id, text, tone }]);
    setTimeout(() => setItems(xs => xs.filter(x => x.id !== id)), tone === 'err' ? 7000 : 4000);
  }, []);
  const view = <div className="toasts" aria-live="polite">{items.map(t => <div key={t.id} className={'toast ' + (t.tone || '')}>{t.text}</div>)}</div>;
  return [push, view];
}

// ------------------------------------------------------------------ login
function Login({ onDone }) {
  const [u, setU] = useState('');
  const [p, setP] = useState('');
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const submit = e => {
    e.preventDefault();
    setErr('');
    run(async () => {
      try {
        const r = await post('auth/login', { username: u, password: p });
        BOOT.csrf = r.csrf;
        onDone();
      } catch (ex) { setErr(ex.message); }
    });
  };
  return (
    <div className="login-wrap">
      <form className="login" onSubmit={submit}>
        <div className="row" style={{ gap: 12, marginBottom: 18 }}>
          <div className="side-logo" style={{ width: 42, height: 42, background: 'var(--navy)', color: '#fff' }}>ผง</div>
          <div>
            <div style={{ fontSize: 18, fontWeight: 600, color: 'var(--heading)' }}>แผนงานและงบประมาณ</div>
            <div className="sm muted">{BOOT.org_name}</div>
          </div>
        </div>
        <div className="card">
          <Field label="ชื่อผู้ใช้หรืออีเมล"><input className="input" value={u} onChange={e => setU(e.target.value)} autoComplete="username" autoFocus required /></Field>
          <Field label="รหัสผ่าน"><input className="input" type="password" value={p} onChange={e => setP(e.target.value)} autoComplete="current-password" required /></Field>
          {err && <Alert tone="red">{err}</Alert>}
          <button className="btn primary" disabled={busy} style={{ height: 40 }}>{busy ? 'กำลังเข้าสู่ระบบ…' : 'เข้าสู่ระบบ'}</button>
        </div>
        <div className="xs faint" style={{ textAlign: 'center', marginTop: 14 }}>เวอร์ชัน {BOOT.version}</div>
      </form>
    </div>
  );
}

// ------------------------------------------------------------------ navigation model
// phase: build phase (spec §14) in which the page arrives; items of later phases are shown dimmed.
const NAV = [
  { id: 'dashboard', label: 'หน้าหลัก', icon: 'home' },
  { id: 'inbox', label: 'งานของฉัน', icon: 'inbox', phase: 2, perm: 'view_projects' },
  { group: 'โครงการ' },
  { id: 'projects', label: 'โครงการทั้งหมด', icon: 'list', perm: 'view_projects' },
  { id: 'propose', label: 'เสนอโครงการใหม่', icon: 'filePlus', phase: 4, perm: 'view_projects' },
  { id: 'adjustments', label: 'คำขอปรับแผน', icon: 'shuffle', phase: 3, perm: 'view_projects' },
  { id: 'import', label: 'นำเข้าแผนที่อนุมัติ', icon: 'upload', perm: 'import' },
  { group: 'พิจารณางบประมาณ', planner: true },
  { id: 'scenario', label: 'ชุดพิจารณา', icon: 'sliders', phase: 4, planner: true },
  { id: 'meetings', label: 'มติที่ประชุม', icon: 'check', phase: 4, planner: true },
  { group: 'กองเงิน', perm: 'view_funds' },
  { id: 'funds', label: 'ภาพรวมกองเงิน', icon: 'wallet', perm: 'view_funds' },
  { id: 'estimates', label: 'ประมาณการรายรับ', icon: 'trend', perm: 'view_funds' },
  { id: 'receive', label: 'บันทึกรับเงิน', icon: 'download', perm: 'post_receipt' },
  { id: 'fund-entry', label: 'ยกมา / ปรับจัดสรร / กันเงิน', icon: 'pen', permAny: ['post_carry_in', 'post_allocation_adjust_up', 'post_reserve'] },
  { id: 'ledger', label: 'สมุดบัญชี', icon: 'book', perm: 'view_ledger' },
  { group: 'การเงิน/พัสดุ', perm: 'view_ledger' },
  { id: 'commitments', label: 'บันทึกผูกพัน', icon: 'pen', phase: 2, perm: 'view_ledger' },
  { id: 'disbursements', label: 'บันทึกเบิกจ่าย', icon: 'receipt', phase: 2, perm: 'view_ledger' },
  { group: 'อื่น ๆ' },
  { id: 'reports', label: 'รายงาน', icon: 'bar', phase: 2, perm: 'view_funds' },
  { id: 'settings', label: 'ตั้งค่า', icon: 'gear', permAny: ['settings', 'admin'] },
  { group: 'ผู้ดูแลระบบ', perm: 'admin' },
  { id: 'users', label: 'ผู้ใช้และบทบาท', icon: 'users', perm: 'admin' },
  { id: 'migrations', label: 'Migrations ฐานข้อมูล', icon: 'db', perm: 'admin' },
  { id: 'backups', label: 'สำรองข้อมูล', icon: 'archive', perm: 'admin' },
  { id: 'audit', label: 'บันทึกการใช้งาน', icon: 'history', perm: 'admin' },
];
const PHASE_NAME = { 2: 'ระยะที่ 2', 3: 'ระยะที่ 3', 4: 'ระยะที่ 4' };

function visibleNav(perms, isPlannerLike) {
  const allowed = n => {
    if (n.perm && !perms[n.perm]) return false;
    if (n.permAny && !n.permAny.some(k => perms[k])) return false;
    if (n.planner && !isPlannerLike) return false;
    return true;
  };
  const out = [];
  NAV.forEach(n => { if (allowed(n)) out.push(n); });
  // drop groups without items after them
  return out.filter((n, i) => !n.group || (out[i + 1] && !out[i + 1].group));
}

function Sidebar({ page, collapsed, onNavigate }) {
  const { meta } = useApp();
  const perms = meta.permissions;
  const isPlannerLike = perms.import || perms.settings;
  const items = visibleNav(perms, isPlannerLike);
  const fy = meta.fiscal_year;
  const start = new Date(fy.starts_on), end = new Date(fy.ends_on), now = new Date(meta.today);
  const total = Math.round((end - start) / 864e5) + 1;
  const day = Math.max(0, Math.min(total, Math.round((now - start) / 864e5) + 1));
  return (
    <aside className="side" aria-label="เมนูหลัก">
      <div className="side-brand">
        <div className="side-logo">ผง</div>
        <div className="tx" style={{ minWidth: 0 }}>
          <div className="t1">แผนงานและงบประมาณ</div>
          <div className="t2" title={meta.org_name}>{meta.org_name}</div>
        </div>
      </div>
      <nav>
        {items.map((n, i) => n.group ? <div key={'g' + i} className="nav-group">{n.group}</div> : (
          <button key={n.id} title={n.phase ? n.label + ' (' + PHASE_NAME[n.phase] + ')' : n.label}
            className={'nav-item' + (page === n.id ? ' on' : '') + (n.phase ? ' off' : '')}
            onClick={() => !n.phase && onNavigate(n.id)} aria-disabled={!!n.phase} aria-current={page === n.id ? 'page' : undefined}>
            <Icon name={n.icon} size={18} />
            <span className="lbl">{n.label}</span>
            {n.phase && <span className="soon">{PHASE_NAME[n.phase]}</span>}
          </button>
        ))}
      </nav>
      <div className="fy-box">
        <div className="row" style={{ justifyContent: 'space-between', fontSize: 12 }}>
          <span style={{ fontWeight: 600 }}>ปีงบประมาณ {fy.year_be}</span>
          <span style={{ color: 'rgba(255,255,255,.65)' }}>วันที่ {day}/{total}</span>
        </div>
        <div className="bar"><div style={{ width: pct(day, total) + '%' }} /></div>
        <div style={{ fontSize: 11.5, color: 'rgba(255,255,255,.6)' }}>{thDate(fy.starts_on)} – {thDate(fy.ends_on)}</div>
      </div>
    </aside>
  );
}

function Topbar({ onToggle, onLogout }) {
  const { meta, setFy } = useApp();
  const [open, setOpen] = useState(false);
  const [pwd, setPwd] = useState(false);
  const [theme, setTheme] = useState(() => { try { return localStorage.getItem('vecplan_theme') || ''; } catch (e) { return ''; } });
  const cycleTheme = () => {
    const next = theme === '' ? 'dark' : theme === 'dark' ? 'light' : '';
    setTheme(next);
    if (next) document.documentElement.setAttribute('data-theme', next); else document.documentElement.removeAttribute('data-theme');
    try { next ? localStorage.setItem('vecplan_theme', next) : localStorage.removeItem('vecplan_theme'); } catch (e) { /* storage blocked */ }
  };
  const u = meta.user;
  const roleText = u.roles.length ? u.roles.map(r => r.label + (r.unit_name ? ' · ' + r.unit_name : '')).join(', ') : 'ยังไม่มีบทบาทในปีนี้';
  return (
    <header className="topbar">
      <button className="icon-btn" onClick={onToggle} title="ยุบ/ขยายเมนู" aria-label="ยุบ/ขยายเมนู"><Icon name="menu" /></button>
      <select className="select" value={meta.fy} onChange={e => setFy(+e.target.value)} aria-label="ปีงบประมาณ"
        style={{ fontWeight: 600, color: 'var(--heading)', flexShrink: 0 }}>
        {meta.fiscal_years.map(f => <option key={f.id} value={f.id}>ปีงบประมาณ {f.year_be}</option>)}
      </select>
      <div style={{ flex: 1 }} />
      <span className="sm muted hide-mobile row" style={{ gap: 6 }} title="เวลาของเซิร์ฟเวอร์">
        <span className="dot" style={{ background: 'var(--green)', width: 7, height: 7 }} />ข้อมูล ณ {thDate(meta.today)}
      </span>
      <button className="icon-btn plain" onClick={cycleTheme} title={'ธีม: ' + (theme === 'dark' ? 'มืด' : theme === 'light' ? 'สว่าง' : 'ตามระบบ')}>
        <Icon name={theme === 'dark' ? 'moon' : 'sun'} size={18} />
      </button>
      <button className="user-chip" onClick={() => setOpen(o => !o)} aria-haspopup="menu" aria-expanded={open} title={u.name + ' · ' + roleText}>
        <div className="avatar">{initials(u.name)}</div>
        <div className="tx" style={{ display: 'flex', flexDirection: 'column', lineHeight: 1.3, minWidth: 0 }}>
          <span className="n">{u.name}</span>
          <span className="r">{u.position_title || roleText}</span>
        </div>
      </button>
      {open && (
        <Fragment>
          <div style={{ position: 'fixed', inset: 0, zIndex: 55 }} onClick={() => setOpen(false)} />
          <div className="menu" role="menu">
            <div style={{ padding: '8px 10px 10px', borderBottom: '1px solid var(--line-soft)', marginBottom: 4 }}>
              <div style={{ fontWeight: 600 }}>{u.name}</div>
              <div className="xs muted">{u.username}</div>
              <div className="xs muted" style={{ marginTop: 4 }}>{roleText}</div>
            </div>
            <button role="menuitem" onClick={() => { setOpen(false); setPwd(true); }}>เปลี่ยนรหัสผ่าน</button>
            <button role="menuitem" onClick={onLogout}>ออกจากระบบ</button>
          </div>
        </Fragment>
      )}
      {pwd && <ChangePassword onClose={() => setPwd(false)} />}
    </header>
  );
}

function ChangePassword({ onClose }) {
  const { toast } = useApp();
  const [f, setF] = useState({ current: '', new: '', confirm: '' });
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const save = () => run(async () => {
    setErr('');
    if (f.new.length < 10) return setErr('รหัสผ่านใหม่ต้องมีอย่างน้อย 10 ตัวอักษร');
    if (f.new !== f.confirm) return setErr('ยืนยันรหัสผ่านไม่ตรงกัน');
    try { await post('auth/password', { current: f.current, new: f.new }); toast('เปลี่ยนรหัสผ่านแล้ว', 'ok'); onClose(); }
    catch (e) { setErr(e.message); }
  });
  return (
    <Modal title="เปลี่ยนรหัสผ่าน" onClose={onClose} footer={<Fragment>
      <button className="btn" onClick={onClose}>ยกเลิก</button>
      <button className="btn primary" onClick={save} disabled={busy}>บันทึก</button>
    </Fragment>}>
      <Field label="รหัสผ่านปัจจุบัน"><input className="input" type="password" value={f.current} onChange={e => setF({ ...f, current: e.target.value })} autoComplete="current-password" /></Field>
      <Field label="รหัสผ่านใหม่" hint="อย่างน้อย 10 ตัวอักษร"><input className="input" type="password" value={f.new} onChange={e => setF({ ...f, new: e.target.value })} autoComplete="new-password" /></Field>
      <Field label="ยืนยันรหัสผ่านใหม่"><input className="input" type="password" value={f.confirm} onChange={e => setF({ ...f, confirm: e.target.value })} autoComplete="new-password" /></Field>
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

function Forbidden() {
  return <Empty icon="lock" title="ไม่มีสิทธิ์เข้าถึงหน้านี้">บทบาทของคุณในปีงบประมาณนี้ไม่ครอบคลุมหน้านี้ ติดต่อผู้ดูแลระบบหากต้องการสิทธิ์เพิ่ม</Empty>;
}

Object.assign(window, { parseHash, useRoute, navigate, useToasts, Login, NAV, Sidebar, Topbar, Forbidden, ChangePassword });
