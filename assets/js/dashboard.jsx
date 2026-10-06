// dashboard.jsx — role-based home (design 5.1 / 5.3, phase-1 data)

function Dashboard() {
  const { meta } = useApp();
  const { data, error, loading, reload } = useApi('dashboard/index');
  const fy = meta.fiscal_year;
  const head = (
    <PageHead crumb="หน้าหลัก" title={data && data.mode === 'funds' ? 'แดชบอร์ดงานวางแผนและงบประมาณ' : 'หน้าหลัก'}>
      {meta.permissions.view_funds && <button className="btn" onClick={() => navigate('funds')}>ภาพรวมกองเงิน</button>}
      {meta.permissions.import && <button className="btn primary" onClick={() => navigate('import')}>นำเข้าแผนที่อนุมัติ</button>}
    </PageHead>
  );
  if (loading && !data) return <div className="stack">{head}<Skeleton /><div className="sm muted" style={{ textAlign: 'center' }}>กำลังคำนวณฐานะเงินจากสมุดบัญชี…</div></div>;
  if (error) return <div className="stack">{head}<LoadError error={error} onRetry={reload} /></div>;
  if (data.mode !== 'funds') return <div className="stack">{head}<PersonalHome data={data} /></div>;

  const t = data.totals;
  const cards = data.cards;
  const total = t.carry_in + t.estimate;
  const cash = t.carry_in + t.receipts + t.adjust_up - t.adjust_down;
  const segs = k => cards.map(c => ({ w: pct(Math.max(0, k(c)), Math.max(1, cards.reduce((a, x) => a + Math.max(0, k(x)), 0))), color: c.color }));
  const projectCount = Object.values(data.status_counts || {}).reduce((a, b) => a + b, 0);
  const approvedCount = (data.status_counts || {}).approved || 0;
  const kpis = [
    { label: 'วงเงินทั้งหมด', value: total, segs: segs(c => c.carry_in + c.estimate), go: 'funds',
      sub: 'ยกมา ' + fmtM(t.carry_in) + ' + ประมาณการรับ ' + fmtM(t.estimate) + ' ล้าน',
      sub2: 'รับจริงแล้ว ' + fmtM(cash) + ' ล้าน (' + (total ? (cash / total * 100).toFixed(1) : '0.0') + '%)' },
    { label: 'อนุมัติแล้ว (จัดสรร)', value: t.allocated, segs: segs(c => c.allocated), go: 'projects',
      sub: approvedCount + ' โครงการ · ' + (total ? (t.allocated / total * 100).toFixed(1) : '0.0') + '% ของวงเงิน',
      sub2: data.baseline_locked_at ? 'แผนตั้งต้นล็อกเมื่อ ' + thDate(data.baseline_locked_at) : 'ยังไม่ล็อกแผนตั้งต้น' },
    { label: 'จ่ายจริง', value: t.spent, segs: segs(c => c.spent), go: 'ledger',
      sub: (t.allocated ? (t.spent / t.allocated * 100).toFixed(1) : '0.0') + '% ของที่อนุมัติ',
      sub2: 'บันทึกเบิกจ่ายเปิดใช้ในระยะที่ 2', sub2c: 'var(--muted)' },
    { label: 'คงเหลือจัดสรรได้', value: t.pool_estimate, segs: segs(c => c.pool_estimate), go: 'funds',
      sub: 'ตามประมาณการ · กันไว้ ' + fmtM(t.reserved) + ' ล้าน',
      sub2: 'ตามรับจริง ' + fmtM(t.pool_actual) + ' ล้าน', sub2c: t.pool_actual < 0 ? 'var(--red-text)' : 'var(--green-fg)', sub2w: 600 },
  ];

  return (
    <div className="stack" style={{ gap: 20 }}>
      {head}
      {data.warnings.length > 0 && (
        <div className="stack" style={{ gap: 8 }}>
          {data.warnings.map((w, i) => (
            <Alert key={i} tone={w.tone === 'red' ? 'red' : 'orange'} title={w.title + (w.tone === 'red' ? ' ' + fmt(w.amount) + ' บาท' : '')}
              action={meta.permissions.view_ledger && <a className="sm nowrap" style={{ fontWeight: 600, color: 'inherit' }} onClick={() => navigate('ledger', { fund: w.fund_id })}>ดูสมุดบัญชี</a>}>
              {w.detail}
            </Alert>
          ))}
        </div>
      )}
      {!data.baseline_locked_at && t.allocated === 0 && (
        <Empty title={'ยังไม่มีแผนตั้งต้นสำหรับปีงบประมาณ ' + fy.year_be}
          actions={<Fragment>
            {meta.permissions.view_funds && <button className="btn" onClick={() => navigate('estimates')}>ตั้งค่าประมาณการรายรับ</button>}
            {meta.permissions.import && <button className="btn primary" onClick={() => navigate('import')}>นำเข้าแผนที่อนุมัติ</button>}
          </Fragment>}>
          แดชบอร์ดจะแสดงยอดอนุมัติและการใช้จ่ายหลังนำเข้าแผนที่อนุมัติ (ระยะที่ 1) หรือยืนยันแผนตั้งต้นจากมติที่ประชุม (ระยะที่ 4)
        </Empty>
      )}

      <div className="grid-kpi">
        {kpis.map(k => (
          <div key={k.label} className="card kpi">
            <div className="row" style={{ justifyContent: 'space-between' }}>
              <span style={{ fontSize: 13, fontWeight: 500, color: 'var(--muted)' }}>{k.label}</span>
              <a className="xs" onClick={() => navigate(k.go)}>ดูรายการ</a>
            </div>
            <div className="row" style={{ alignItems: 'baseline', gap: 6 }}><span className="v">{fmtM(k.value)}</span><span className="sm muted">ล้านบาท</span></div>
            <div className="seg">{k.segs.map((s, i) => <div key={i} style={{ width: s.w + '%', background: s.color }} />)}</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 2, fontSize: 12.5, lineHeight: 1.5 }}>
              <span style={{ color: 'var(--text-3)' }}>{k.sub}</span>
              <span style={{ color: k.sub2c || 'var(--text-3)', fontWeight: k.sub2w || 400 }}>{k.sub2}</span>
            </div>
          </div>
        ))}
      </div>

      <div className="grid-auto">
        <FundPositionBars cards={cards} />
        <DivisionCard divisions={data.divisions} statusCounts={data.status_counts} projectCount={projectCount} />
      </div>

      {data.recent && (
        <div className="card">
          <div className="card-h"><h3>รายการล่าสุดในสมุดบัญชี</h3><a className="sm" onClick={() => navigate('ledger')}>เปิดสมุดบัญชี</a></div>
          {data.recent.length === 0 && <div className="card-b sm muted">ยังไม่มีรายการ</div>}
          {data.recent.map(l => (
            <div key={l.id} style={{ display: 'grid', gridTemplateColumns: 'auto minmax(0,1fr) auto', gap: 12, alignItems: 'center', padding: '10px 18px', borderBottom: '1px solid var(--row-line)' }}>
              <span className="sw" style={{ width: 8, height: 8, borderRadius: 2, background: l.fund_color }} />
              <div style={{ minWidth: 0 }}>
                <div className="sm" style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', textDecoration: l.reversed ? 'line-through' : 'none' }}>
                  {l.type_label} · {l.project_code ? l.project_code + ' · ' : ''}{l.fund_name}{l.note ? ' · ' + l.note : ''}
                </div>
                <div className="xs muted mono">{l.entry_no} · {thDate(l.entry_date)}{l.reference_no ? ' · ' + l.reference_no : ''}</div>
              </div>
              <span className="sm num" style={{ fontWeight: 600, color: l.signed > 0 ? 'var(--green-fg)' : 'var(--text)' }}>{l.signed ? fmtSigned(l.signed) : fmt(l.amount)}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/** Horizontal bar per fund: spent / committed / allocated-unused / reserved, with a marker at cash received. */
function FundPositionBars({ cards }) {
  return (
    <div className="card">
      <div className="card-h">
        <h3>ฐานะเงินรายกอง</h3>
        <div className="legend">
          <span><span className="sw" style={{ width: 12, height: 8, background: '#33415A' }} />จ่ายจริง</span>
          <span><span className="sw" style={{ width: 12, height: 8, background: '#33415A', opacity: .55 }} />ผูกพัน</span>
          <span><span className="sw" style={{ width: 12, height: 8, background: '#33415A', opacity: .22 }} />จัดสรรยังไม่ใช้</span>
          <span><span className="sw hatch" style={{ width: 12, height: 8 }} />กันไว้</span>
          <span><span style={{ width: 2, height: 12, background: 'var(--heading)', display: 'inline-block' }} />รับจริงสะสม</span>
        </div>
      </div>
      <div className="card-b stack" style={{ gap: 20 }}>
        {cards.length === 0 && <div className="sm muted">ยังไม่มีแหล่งเงินในปีนี้</div>}
        {cards.map(f => {
          const total = Math.max(f.carry_in + f.estimate + f.adjust_up - f.adjust_down, f.carry_in + f.receipts + f.adjust_up - f.adjust_down, f.allocated + f.reserved, 1);
          const cash = f.carry_in + f.receipts + f.adjust_up - f.adjust_down;
          const unused = Math.max(0, f.allocated - f.spent - f.committed_open);
          return (
            <div key={f.id} className="stack" style={{ gap: 8 }}>
              <div className="row wrap" style={{ justifyContent: 'space-between', alignItems: 'baseline' }}>
                <a onClick={() => navigate('ledger', { fund: f.id })} className="row" style={{ fontWeight: 600, fontSize: 14, color: 'var(--text)' }}>
                  <span className="sw" style={{ background: f.color }} />{f.name}
                </a>
                <span className="sm muted">คงเหลือจัดสรรได้ (จริง) <b style={{ color: f.pool_actual < 0 ? 'var(--red-text)' : 'var(--green-fg)', fontWeight: 600 }}>{fmt(f.pool_actual)}</b></span>
              </div>
              <div className="fundbar" title={'วงเงิน ' + fmt(total)}>
                <div className="track">
                  <div style={{ width: pct(f.spent, total) + '%', background: f.color }} />
                  <div style={{ width: pct(f.committed_open, total) + '%', background: f.color, opacity: .55 }} />
                  <div style={{ width: pct(unused, total) + '%', background: f.color, opacity: .22 }} />
                  <div className="hatch" style={{ width: pct(f.reserved, total) + '%' }} />
                </div>
                <div className="marker" title={'รับจริงสะสม ' + fmt(cash)} style={{ left: 'calc(' + pct(cash, total) + '% - 1px)' }} />
              </div>
              <div className="mini-grid">
                <div>จ่ายจริง<b>{fmt(f.spent)}</b></div>
                <div>ผูกพัน<b>{fmt(f.committed_open)}</b></div>
                <div>จัดสรรแล้ว<b>{fmt(f.allocated)}</b></div>
                <div style={{ textAlign: 'right' }}>ประมาณการทั้งปี<b>{fmt(f.carry_in + f.estimate)}</b></div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function DivisionCard({ divisions, statusCounts, projectCount }) {
  const max = Math.max(1, ...divisions.map(d => d.allocated));
  return (
    <div className="card">
      <div className="card-h">
        <h3>งบที่อนุมัติตามฝ่าย</h3>
        <span className="sm muted">{projectCount} โครงการในปีนี้</span>
      </div>
      <div className="card-b stack" style={{ gap: 12 }}>
        {divisions.length === 0 && <div className="sm muted">ยังไม่มีโครงการ</div>}
        {divisions.map(d => (
          <div key={d.name} style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1.4fr) minmax(0,1fr) 92px', gap: 12, alignItems: 'center', fontSize: 13 }}>
            <span style={{ lineHeight: 1.4 }}>{d.name} <span className="xs muted">· {d.projects} โครงการ</span></span>
            <div style={{ height: 10, borderRadius: 3, background: 'var(--track)', overflow: 'hidden' }}><div style={{ width: pct(d.allocated, max) + '%', height: '100%', background: '#1E4C9A' }} /></div>
            <span className="num" style={{ fontWeight: 500 }}>{fmtM(d.allocated)} ล.</span>
          </div>
        ))}
        {Object.keys(statusCounts || {}).length > 0 && (
          <div className="row wrap" style={{ gap: 6, paddingTop: 6, borderTop: '1px solid var(--row-line)' }}>
            {Object.entries(statusCounts).map(([s, n]) => <span key={s} className="row" style={{ gap: 4 }}><StatusBadge status={s} /><span className="xs muted">{n}</span></span>)}
          </div>
        )}
      </div>
    </div>
  );
}

/** Home for proposers / unit heads (design 5.3, mobile-first). */
function PersonalHome({ data }) {
  const { meta } = useApp();
  const mine = data.my_projects || [];
  if (meta.permissions.admin && !meta.permissions.view_projects) {
    return (
      <div className="grid-cards">
        {[['users', 'users', 'ผู้ใช้และบทบาท', 'เพิ่มผู้ใช้และกำหนดบทบาท/หน่วยงานต่อปีงบประมาณ'], ['migrations', 'db', 'Migrations ฐานข้อมูล', 'ตรวจสถานะและปรับปรุงโครงสร้างฐานข้อมูล'],
          ['backups', 'archive', 'สำรองข้อมูล', 'สำรองและดาวน์โหลดไฟล์ฐานข้อมูล'], ['audit', 'history', 'บันทึกการใช้งาน', 'ตรวจสอบการกระทำทั้งหมดในระบบ']].map(([p, ic, t, d]) => (
          <a key={p} className="card card-b" onClick={() => navigate(p)} style={{ display: 'flex', gap: 14, color: 'var(--text)', textDecoration: 'none' }}>
            <div className="empty" style={{ padding: 0, border: 0, background: 'none' }}><div className="ic"><Icon name={ic} size={22} /></div></div>
            <div><div style={{ fontWeight: 600, color: 'var(--heading)' }}>{t}</div><div className="sm muted">{d}</div></div>
          </a>
        ))}
      </div>
    );
  }
  if (!meta.user.roles.length) {
    return <Empty icon="lock" title="ยังไม่มีบทบาทในปีงบประมาณนี้">ติดต่อผู้ดูแลระบบเพื่อกำหนดบทบาทและหน่วยงานของคุณ</Empty>;
  }
  return (
    <div className="stack">
      <Alert tone="blue" title="ระยะที่ 1">การเสนอโครงการ ขออนุญาตดำเนินโครงการ และรายงานผล จะเปิดใช้งานในระยะถัดไป ขณะนี้ดูโครงการที่อนุมัติแล้วได้</Alert>
      <div className="card">
        <div className="card-h"><h3>โครงการของฉัน ({mine.length})</h3>{meta.permissions.view_projects && <a className="sm" onClick={() => navigate('projects')}>โครงการของหน่วยงาน</a>}</div>
        {mine.length === 0 && <div className="card-b sm muted">ยังไม่มีโครงการที่คุณเป็นผู้รับผิดชอบในปีนี้</div>}
        {mine.map(p => (
          <a key={p.id} onClick={() => navigate('project', { id: p.id })} style={{ display: 'block', padding: '12px 18px', borderBottom: '1px solid var(--row-line)', color: 'var(--text)', textDecoration: 'none' }}>
            <div className="row wrap" style={{ justifyContent: 'space-between' }}>
              <span className="xs mono muted">{p.code} · {p.unit_name}</span><StatusBadge status={p.status} />
            </div>
            <div style={{ fontWeight: 500, margin: '4px 0 8px' }}>{p.title}</div>
            <div className="seg"><div style={{ width: pct(p.spent, p.allocated) + '%', background: 'var(--navy)' }} /></div>
            <div className="xs muted" style={{ marginTop: 4 }}>อนุมัติ {fmt(p.allocated)} · ใช้ไปแล้ว {fmt(p.spent)}</div>
          </a>
        ))}
      </div>
    </div>
  );
}

Object.assign(window, { Dashboard, FundPositionBars, DivisionCard, PersonalHome });
