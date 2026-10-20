// overview.jsx — ภาพรวมโครงการ: summary charts for tracking project execution (plain SVG, no chart library)

const TRACK = {
  on_track: ['ตามแผน', 'var(--viz-good)'],
  behind: ['ล่าช้า', 'var(--viz-warn)'],
  critical: ['ล่าช้ามาก', 'var(--viz-bad)'],
  upcoming: ['ยังไม่ถึงกำหนด', 'var(--viz-idle)'],
  done: ['ใช้เงินครบ/ปิดแล้ว', 'var(--viz-actual)'],
};
const STAGE_COLOR = {
  proposing: 'var(--viz-seq-1)', approved: 'var(--viz-seq-2)', in_progress: 'var(--viz-seq-3)',
  awaiting_report: 'var(--viz-seq-4)', closed: 'var(--viz-seq-5)', dropped: 'var(--viz-idle)',
};

function fmtCompact(n) {
  const v = Math.abs(Number(n) || 0), s = n < 0 ? '−' : '';
  if (v >= 1e6) return s + (v / 1e6).toFixed(v >= 1e8 ? 0 : 1).replace(/\.0$/, '') + ' ล.';
  if (v >= 1e3) return s + (v / 1e3).toFixed(0) + 'K';
  return s + Math.round(v);
}
function niceMax(v) {
  if (v <= 0) return 1;
  const p = Math.pow(10, Math.floor(Math.log10(v)));
  const n = v / p;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * p;
}

/** Width of an element, kept current with ResizeObserver (charts draw in real pixels so text never scales). */
function useWidth() {
  const ref = useRef(null);
  const [w, setW] = useState(0);
  useEffect(() => {
    if (!ref.current) return;
    setW(ref.current.clientWidth);
    if (!window.ResizeObserver) return;
    const ro = new ResizeObserver(es => setW(es[0].contentRect.width));
    ro.observe(ref.current);
    return () => ro.disconnect();
  }, []);
  return [ref, w];
}

/** Hover tooltip positioned inside a relative wrapper. */
function useTip() {
  const [tip, setTip] = useState(null);
  const show = (e, content) => {
    const box = e.currentTarget.closest('.viz').getBoundingClientRect();
    setTip({ x: e.clientX - box.left, y: e.clientY - box.top, w: box.width, content });
  };
  const view = tip && (
    <div className="viz-tip" style={{ left: Math.min(tip.x + 12, tip.w - 210), top: tip.y + 14 }}>{tip.content}</div>
  );
  return [show, () => setTip(null), view];
}
function TipRow({ color, label, value, line }) {
  return (
    <div className="row" style={{ justifyContent: 'space-between', gap: 14 }}>
      <span className="row" style={{ gap: 6 }}>
        {color && (line ? <span style={{ width: 12, height: 2, background: color, borderRadius: 1 }} /> : <span className="sw" style={{ background: color }} />)}{label}
      </span>
      <b className="num" style={{ fontWeight: 600 }}>{value}</b>
    </div>
  );
}

// ------------------------------------------------------------------ page
function OverviewPage() {
  const { meta } = useApp();
  const route = parseHash();
  const unit = route.params.unit || '';
  const { data, error, loading, reload } = useApi('overview/index', unit ? { unit } : {});
  const [table, setTable] = useState(false);
  if (!meta.permissions.view_projects) return <Forbidden />;
  const head = (
    <PageHead crumb="หน้าหลัก › ภาพรวมโครงการ" title={'ภาพรวมการดำเนินโครงการ ปีงบประมาณ ' + meta.fiscal_year.year_be}>
      <div style={{ width: 240 }}><UnitPicker value={unit} onChange={id => navigate('overview', { unit: id })} placeholder="ทุกหน่วยงาน" /></div>
      {unit && <button className="btn" onClick={() => navigate('overview')}>ล้างตัวกรอง</button>}
      <button className="btn" onClick={() => setTable(t => !t)}>{table ? 'แสดงเป็นกราฟ' : 'แสดงเป็นตาราง'}</button>
    </PageHead>
  );
  if (loading && !data) return <div className="stack">{head}<Skeleton rows={6} /></div>;
  if (error) return <div className="stack">{head}<LoadError error={error} onRetry={reload} /></div>;
  const d = data;
  const t = d.totals;
  if (!t.projects) {
    return <div className="stack">{head}<Empty icon="bar" title="ยังไม่มีโครงการให้สรุป">
      {unit ? 'หน่วยงานที่เลือกยังไม่มีโครงการในปีงบประมาณนี้' : 'ภาพรวมจะแสดงเมื่อมีโครงการในปีงบประมาณนี้'}</Empty></div>;
  }
  const spentPct = t.allocated ? t.spent / t.allocated * 100 : 0;
  const expectedPct = t.allocated ? t.expected / t.allocated * 100 : 0;
  const late = d.track.behind + d.track.critical;
  const fyPct = d.fy_position / 12 * 100;

  return (
    <div className="stack" style={{ gap: 20 }}>
      {head}
      <div className="grid-kpi">
        <StatTile label="โครงการทั้งหมด" value={t.projects.toLocaleString()} unit="โครงการ"
          sub={t.active + ' โครงการได้รับจัดสรรและอยู่ระหว่างดำเนินการ'} />
        <StatTile label="งบที่อนุมัติ (จัดสรร)" value={fmtM(t.allocated)} unit="ล้านบาท"
          sub={'จากยอดขอ ' + fmtM(t.requested) + ' ล้าน (' + (t.requested ? (t.allocated / t.requested * 100).toFixed(1) : '0.0') + '%)'} />
        <StatTile label="เบิกจ่ายแล้ว" value={spentPct.toFixed(1) + '%'} unit={fmtM(t.spent) + ' ล้านบาท'}
          meter={{ value: spentPct, mark: expectedPct }}
          sub={<span>ตามแผนควรถึง <b>{expectedPct.toFixed(1)}%</b>{' · '}
            <span style={{ color: spentPct + 0.05 >= expectedPct ? 'var(--green-fg)' : 'var(--red-text)', fontWeight: 600 }}>
              {spentPct + 0.05 >= expectedPct ? 'เร็วกว่า/ตามแผน' : 'ช้ากว่าแผน ' + (expectedPct - spentPct).toFixed(1) + ' จุด'}</span></span>} />
        <StatTile label="โครงการที่ต้องติดตาม" value={late.toLocaleString()} unit="โครงการ" tone={late ? 'warn' : null}
          sub={d.track.critical + ' ล่าช้ามาก · ' + d.track.behind + ' ล่าช้า · ผ่านไปแล้ว ' + fyPct.toFixed(0) + '% ของปี'} />
      </div>

      {table ? <OverviewTables d={d} /> : (
        <Fragment>
          <div className="grid-overview">
            <ChartCard title="การเบิกจ่ายสะสม: แผนเทียบกับจริง" sub="แผนกระจายวงเงินที่อนุมัติตามไตรมาสที่วางไว้ของแต่ละโครงการ"
              legend={[['var(--viz-plan)', 'แผนสะสม', true], ['var(--viz-actual)', 'จ่ายจริงสะสม', true]]}>
              <SCurve curve={d.curve} position={d.fy_position} />
            </ChartCard>
            <ChartCard title="สถานะการเบิกจ่ายเทียบแผน" sub={t.active + ' โครงการที่ได้รับจัดสรร'}>
              <Donut items={Object.keys(TRACK).map(k => ({ key: k, label: TRACK[k][0], color: TRACK[k][1], value: d.track[k] }))}
                center={t.active ? Math.round((d.track.on_track + d.track.done) / t.active * 100) + '%' : '–'} centerSub="ตามแผน" unit="โครงการ" />
            </ChartCard>
          </div>

          <div className="grid-auto">
            <ChartCard title="ความก้าวหน้าตามฝ่าย" sub="แถบ = วงเงินที่อนุมัติ · สีเข้ม = จ่ายจริง · เส้นตั้ง = ควรถึงตามแผน"
              legend={[['var(--viz-actual)', 'จ่ายจริง'], ['var(--viz-actual-soft)', 'คงเหลือ'], ['var(--heading)', 'ตามแผน', true]]}>
              <DivisionBars divisions={d.divisions} />
            </ChartCard>
            <ChartCard title="แผนการใช้เงินรายไตรมาส" sub="วงเงินโครงการที่วางแผนใช้ในแต่ละไตรมาส เทียบกับที่จ่ายจริง"
              legend={[['var(--viz-plan)', 'ตามแผน'], ['var(--viz-actual)', 'จ่ายจริง']]}>
              <QuarterColumns quarters={d.quarters} curve={d.curve} />
            </ChartCard>
          </div>

          <div className="grid-overview-3">
            <ChartCard title="ขั้นตอนของโครงการ" sub={t.projects + ' โครงการ'}>
              <StagePipeline stages={d.stages} total={t.projects} />
            </ChartCard>
            <ChartCard title="งบที่อนุมัติตามแหล่งเงิน">
              <Donut items={d.funds.map(f => ({ key: f.id, label: f.name, color: f.color, value: f.allocated, extra: 'จ่ายแล้ว ' + fmt(f.spent) }))}
                center={fmtM(t.allocated)} centerSub="ล้านบาท" money onClick={meta.permissions.view_ledger ? k => navigate('ledger', { fund: k }) : null} />
            </ChartCard>
            <ChartCard title="จ่ายจริงตามหมวดรายจ่าย">
              <CategoryBars categories={d.categories} />
            </ChartCard>
          </div>
        </Fragment>
      )}

      <WatchList watch={d.watch} total={d.watch_total} />
    </div>
  );
}

function StatTile({ label, value, unit, sub, meter, tone }) {
  return (
    <div className="card kpi">
      <span style={{ fontSize: 13, fontWeight: 500, color: 'var(--muted)' }}>{label}</span>
      <div className="row wrap" style={{ alignItems: 'baseline', gap: 6 }}>
        <span className="v" style={tone === 'warn' ? { color: 'var(--orange-fg)' } : null}>{value}</span><span className="sm muted">{unit}</span>
      </div>
      {meter && (
        <div className="meter" title={'จ่ายจริง ' + meter.value.toFixed(1) + '% · ตามแผน ' + meter.mark.toFixed(1) + '%'}>
          <div style={{ width: Math.min(100, meter.value) + '%' }} />
          <span style={{ left: 'calc(' + Math.min(100, meter.mark) + '% - 1px)' }} />
        </div>
      )}
      <div style={{ fontSize: 12.5, lineHeight: 1.5, color: 'var(--text-3)' }}>{sub}</div>
    </div>
  );
}

function ChartCard({ title, sub, legend, children }) {
  return (
    <div className="card">
      <div className="card-h" style={{ alignItems: 'flex-start' }}>
        <div><h3>{title}</h3>{sub && <div className="xs muted" style={{ marginTop: 3 }}>{sub}</div>}</div>
        {legend && (
          <div className="legend">
            {legend.map(([c, l, line]) => <span key={l}>{line ? <span style={{ width: 14, height: 2, background: c, borderRadius: 1 }} /> : <span className="sw" style={{ width: 10, height: 10, background: c }} />}{l}</span>)}
          </div>
        )}
      </div>
      <div className="card-b">{children}</div>
    </div>
  );
}

// ------------------------------------------------------------------ cumulative plan vs actual (line + area, crosshair tooltip)
function SCurve({ curve, position }) {
  const [ref, w] = useWidth();
  const [hover, setHover] = useState(null);
  const H = 260, pad = { l: 44, r: 16, t: 14, b: 28 };
  const iw = Math.max(10, w - pad.l - pad.r), ih = H - pad.t - pad.b;
  const max = niceMax(Math.max(1, ...curve.map(c => Math.max(c.plan, c.actual || 0))));
  // Points sit at month ends: x(0) = start of year, x(m+1) = end of month m.
  const x = i => pad.l + iw * i / 12;
  const y = v => pad.t + ih - ih * v / max;
  const planPts = [[x(0), y(0)], ...curve.map((c, i) => [x(i + 1), y(c.plan)])];
  const act = curve.filter(c => c.actual !== null);
  const actPts = [[x(0), y(0)], ...act.map((c, i) => [x(i + 1), y(c.actual)])];
  const path = pts => pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join('');
  const area = pts => path(pts) + 'L' + pts[pts.length - 1][0].toFixed(1) + ',' + y(0) + 'L' + pts[0][0].toFixed(1) + ',' + y(0) + 'Z';
  const ticks = [0, .25, .5, .75, 1].map(f => f * max);
  const last = act[act.length - 1];
  const h = hover !== null ? curve[hover] : null;
  const onMove = e => {
    const r = e.currentTarget.getBoundingClientRect();
    const i = Math.round((e.clientX - r.left - pad.l) / iw * 12) - 1;
    setHover(i >= 0 && i < 12 ? i : null);
  };
  return (
    <div className="viz" ref={ref} style={{ position: 'relative' }}>
      {w > 0 && (
        <svg width={w} height={H} role="img" aria-label="กราฟการเบิกจ่ายสะสมเทียบแผนรายเดือน" onMouseMove={onMove} onMouseLeave={() => setHover(null)} style={{ display: 'block' }}>
          {ticks.map(v => (
            <g key={v}>
              <line x1={pad.l} x2={w - pad.r} y1={y(v)} y2={y(v)} stroke="var(--viz-grid)" />
              <text x={pad.l - 8} y={y(v) + 4} textAnchor="end" className="viz-ax">{fmtCompact(v)}</text>
            </g>
          ))}
          {FISCAL_MONTHS.map((m, i) => (
            (iw > 420 || i % 2 === 0) && <text key={m} x={x(i + 1)} y={H - 8} textAnchor="middle" className="viz-ax">{m}</text>
          ))}
          {position > 0 && position < 12 && (
            <g>
              <line x1={x(position)} x2={x(position)} y1={pad.t} y2={pad.t + ih} stroke="var(--viz-axis)" />
              <text x={x(position) + 4} y={pad.t + 10} className="viz-ax">วันนี้</text>
            </g>
          )}
          <path d={area(planPts)} fill="var(--viz-plan)" opacity=".10" />
          <path d={path(planPts)} fill="none" stroke="var(--viz-plan)" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
          {act.length > 0 && <path d={area(actPts)} fill="var(--viz-actual)" opacity=".12" />}
          {act.length > 0 && <path d={path(actPts)} fill="none" stroke="var(--viz-actual)" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />}
          {last && (
            <g>
              <circle cx={x(act.length)} cy={y(last.actual)} r="5" fill="var(--viz-actual)" stroke="var(--surface)" strokeWidth="2" />
              <text x={Math.min(x(act.length) + 8, w - pad.r - 60)} y={y(last.actual) - 9} className="viz-lbl">{fmtCompact(last.actual)}</text>
            </g>
          )}
          {h && (
            <g>
              <line x1={x(hover + 1)} x2={x(hover + 1)} y1={pad.t} y2={pad.t + ih} stroke="var(--viz-axis)" />
              <circle cx={x(hover + 1)} cy={y(h.plan)} r="4" fill="var(--viz-plan)" stroke="var(--surface)" strokeWidth="2" />
              {h.actual !== null && <circle cx={x(hover + 1)} cy={y(h.actual)} r="4" fill="var(--viz-actual)" stroke="var(--surface)" strokeWidth="2" />}
            </g>
          )}
        </svg>
      )}
      {h && (
        <div className="viz-tip" style={{ left: Math.min(x(hover + 1) + 12, w - 220), top: 20 }}>
          <div style={{ fontWeight: 600, marginBottom: 4 }}>สิ้นเดือน {FISCAL_MONTHS[hover]}</div>
          <TipRow color="var(--viz-plan)" line label="แผนสะสม" value={fmt(h.plan)} />
          <TipRow color="var(--viz-actual)" line label="จ่ายจริงสะสม" value={h.actual === null ? '–' : fmt(h.actual)} />
          <TipRow label="จ่ายในเดือนนี้" value={fmt(h.spent)} />
          {h.actual !== null && h.plan > 0 && <TipRow label="เทียบแผน" value={(h.actual / h.plan * 100).toFixed(1) + '%'} />}
        </div>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ donut with legend list
function Donut({ items, center, centerSub, unit, money, onClick }) {
  const [show, hide, tipView] = useTip();
  const list = items.filter(i => i.value > 0);
  const total = list.reduce((a, i) => a + i.value, 0);
  const R = 70, r = 48, C = 90;
  let a0 = -Math.PI / 2;
  const gap = list.length > 1 ? 0.025 : 0;
  const arc = (s, e) => {
    const big = e - s > Math.PI ? 1 : 0;
    const p = (rad, ang) => [C + rad * Math.cos(ang), C + rad * Math.sin(ang)].map(v => v.toFixed(2)).join(',');
    return 'M' + p(R, s) + 'A' + R + ',' + R + ' 0 ' + big + ' 1 ' + p(R, e) + 'L' + p(r, e) + 'A' + r + ',' + r + ' 0 ' + big + ' 0 ' + p(r, s) + 'Z';
  };
  const val = v => money ? fmt(v) + ' บาท' : v.toLocaleString() + ' ' + (unit || '');
  if (!total) return <div className="sm muted">ยังไม่มีข้อมูล</div>;
  return (
    <div className="viz donut" style={{ position: 'relative' }}>
      <svg width="180" height="180" viewBox="0 0 180 180" role="img" aria-label="แผนภูมิวงกลมสัดส่วน" style={{ flexShrink: 0 }}>
        {list.length === 1 ? (
          <circle cx={C} cy={C} r={(R + r) / 2} fill="none" stroke={list[0].color} strokeWidth={R - r}
            onMouseMove={e => show(e, <TipRow color={list[0].color} label={list[0].label} value={val(list[0].value)} />)} onMouseLeave={hide} />
        ) : list.map(i => {
          const span = i.value / total * Math.PI * 2;
          const s = a0 + gap / 2, e = a0 + Math.max(span - gap / 2, gap / 2 + 0.001);
          a0 += span;
          return <path key={i.key} d={arc(s, e)} fill={i.color} style={{ cursor: onClick ? 'pointer' : 'default' }} onClick={() => onClick && onClick(i.key)}
            onMouseMove={ev => show(ev, <Fragment><TipRow color={i.color} label={i.label} value={val(i.value)} />
              <TipRow label="สัดส่วน" value={(i.value / total * 100).toFixed(1) + '%'} />{i.extra && <div className="xs muted">{i.extra}</div>}</Fragment>)}
            onMouseLeave={hide} />;
        })}
        <text x={C} y={C + 2} textAnchor="middle" className="viz-hero">{center}</text>
        <text x={C} y={C + 20} textAnchor="middle" className="viz-ax">{centerSub}</text>
      </svg>
      <div className="stack" style={{ gap: 7, minWidth: 0, flex: 1 }}>
        {items.map(i => (
          <div key={i.key} className="row" style={{ gap: 8, fontSize: 13, opacity: i.value > 0 ? 1 : .5 }}>
            <span className="sw" style={{ background: i.color }} />
            <span className="grow" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={i.label}>{i.label}</span>
            <span className="num" style={{ fontWeight: 600 }}>{money ? fmtM(i.value) : i.value.toLocaleString()}</span>
            <span className="xs muted num" style={{ width: 42 }}>{total ? (i.value / total * 100).toFixed(0) : 0}%</span>
          </div>
        ))}
      </div>
      {tipView}
    </div>
  );
}

// ------------------------------------------------------------------ division progress bars
function DivisionBars({ divisions }) {
  const [show, hide, tipView] = useTip();
  const max = Math.max(1, ...divisions.map(d => d.allocated));
  if (!divisions.length) return <div className="sm muted">ยังไม่มีข้อมูล</div>;
  return (
    <div className="viz stack" style={{ gap: 14, position: 'relative' }}>
      {divisions.map(d => {
        const p = d.allocated ? d.spent / d.allocated * 100 : 0;
        return (
          <div key={d.name} onMouseMove={e => show(e, <Fragment>
            <div style={{ fontWeight: 600, marginBottom: 4 }}>{d.name}</div>
            <TipRow label="โครงการ" value={d.projects} />
            <TipRow label="อนุมัติ" value={fmt(d.allocated)} />
            <TipRow color="var(--viz-actual)" label="จ่ายจริง" value={fmt(d.spent) + ' (' + p.toFixed(1) + '%)'} />
            <TipRow color="var(--heading)" line label="ควรถึงตามแผน" value={fmt(d.expected)} />
          </Fragment>)} onMouseLeave={hide}>
            <div className="row" style={{ justifyContent: 'space-between', fontSize: 13, marginBottom: 5 }}>
              <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{d.name} <span className="xs muted">· {d.projects} โครงการ</span></span>
              <span className="nowrap"><b style={{ fontWeight: 600 }}>{p.toFixed(1)}%</b> <span className="xs muted">ของ {fmtM(d.allocated)} ล.</span></span>
            </div>
            <div className="divbar" style={{ width: Math.max(4, d.allocated / max * 100) + '%' }}>
              <div style={{ width: (d.allocated ? Math.min(100, p) : 0) + '%' }} />
              {d.allocated > 0 && <span style={{ left: 'calc(' + Math.min(100, d.expected / d.allocated * 100) + '% - 1px)' }} />}
            </div>
          </div>
        );
      })}
      {tipView}
    </div>
  );
}

// ------------------------------------------------------------------ quarter columns (plan vs actual)
function QuarterColumns({ quarters, curve }) {
  const [ref, w] = useWidth();
  const [show, hide, tipView] = useTip();
  const actual = [0, 1, 2, 3].map(q => curve.slice(q * 3, q * 3 + 3).reduce((a, c) => a + c.spent, 0));
  const H = 240, pad = { l: 44, r: 8, t: 22, b: 40 };
  const ih = H - pad.t - pad.b, iw = Math.max(10, w - pad.l - pad.r);
  const max = niceMax(Math.max(1, ...quarters.map(q => q.planned), ...actual));
  const y = v => pad.t + ih - ih * v / max;
  const band = iw / 4, bw = Math.min(24, band / 3.2);
  const col = (x, v, color, key) => {
    const h = Math.max(0, ih * v / max), r = Math.min(4, h);
    if (h <= 0) return null;
    // Rounded data end, square at the baseline.
    return <path key={key} d={'M' + x + ',' + (pad.t + ih) + 'v' + (-(h - r)) + 'q0,' + (-r) + ' ' + r + ',' + (-r) + 'h' + (bw - 2 * r) + 'q' + r + ',0 ' + r + ',' + r + 'v' + (h - r) + 'Z'} fill={color} />;
  };
  return (
    <div className="viz" ref={ref} style={{ position: 'relative' }}>
      {w > 0 && (
        <svg width={w} height={H} role="img" aria-label="กราฟแท่งแผนการใช้เงินรายไตรมาส" style={{ display: 'block' }}>
          {[0, .5, 1].map(f => (
            <g key={f}>
              <line x1={pad.l} x2={w - pad.r} y1={y(f * max)} y2={y(f * max)} stroke="var(--viz-grid)" />
              <text x={pad.l - 8} y={y(f * max) + 4} textAnchor="end" className="viz-ax">{fmtCompact(f * max)}</text>
            </g>
          ))}
          {quarters.map((q, i) => {
            const cx = pad.l + band * i + band / 2;
            const tip = e => show(e, <Fragment>
              <div style={{ fontWeight: 600, marginBottom: 4 }}>{QUARTERS[q.quarter]}</div>
              <TipRow color="var(--viz-plan)" label="ตามแผน" value={fmt(q.planned)} />
              <TipRow color="var(--viz-actual)" label="จ่ายจริง" value={fmt(actual[i])} />
              <TipRow label="โครงการที่วางแผนไว้" value={q.count} />
            </Fragment>);
            return (
              <g key={q.quarter} onMouseMove={tip} onMouseLeave={hide}>
                <rect x={pad.l + band * i} y={pad.t} width={band} height={ih} fill="transparent" />
                {col(cx - bw - 1, q.planned, 'var(--viz-plan)', 'p')}
                {col(cx + 1, actual[i], 'var(--viz-actual)', 'a')}
                {q.planned > 0 && <text x={cx - bw / 2 - 1} y={y(q.planned) - 6} textAnchor="middle" className="viz-lbl">{fmtCompact(q.planned)}</text>}
                <text x={cx} y={H - 22} textAnchor="middle" className="viz-ax" style={{ fontWeight: 600 }}>{band > 80 ? 'ไตรมาส ' : 'Q'}{q.quarter}</text>
                <text x={cx} y={H - 8} textAnchor="middle" className="viz-ax">{q.count}{band > 80 ? ' โครงการ' : ''}</text>
              </g>
            );
          })}
        </svg>
      )}
      {tipView}
    </div>
  );
}

// ------------------------------------------------------------------ pipeline stages (one stacked bar + list)
function StagePipeline({ stages, total }) {
  const [show, hide, tipView] = useTip();
  const list = stages.filter(s => s.count > 0);
  return (
    <div className="viz stack" style={{ gap: 14, position: 'relative' }}>
      <div className="stackbar">
        {list.map(s => (
          <div key={s.key} style={{ flexGrow: s.count, background: STAGE_COLOR[s.key] }}
            onMouseMove={e => show(e, <Fragment><TipRow color={STAGE_COLOR[s.key]} label={s.label} value={s.count + ' โครงการ'} />
              <TipRow label="วงเงินอนุมัติ" value={fmt(s.allocated)} /></Fragment>)} onMouseLeave={hide} />
        ))}
      </div>
      <div className="stack" style={{ gap: 8 }}>
        {stages.map(s => (
          <div key={s.key} className="row" style={{ gap: 8, fontSize: 13, opacity: s.count ? 1 : .5 }}>
            <span className="sw" style={{ background: STAGE_COLOR[s.key] }} />
            <span className="grow">{s.label}</span>
            <span className="num" style={{ fontWeight: 600 }}>{s.count}</span>
            <span className="xs muted num" style={{ width: 42 }}>{total ? (s.count / total * 100).toFixed(0) : 0}%</span>
          </div>
        ))}
      </div>
      {tipView}
    </div>
  );
}

// ------------------------------------------------------------------ spending by expense category
function CategoryBars({ categories }) {
  const [show, hide, tipView] = useTip();
  if (!categories.length) return <div className="sm muted">ยังไม่มีการบันทึกจ่ายจริงในปีนี้</div>;
  const top = categories.slice(0, 7);
  const rest = categories.slice(7).reduce((a, c) => a + c.amount, 0);
  if (rest) top.push({ name: 'อื่น ๆ (' + (categories.length - 7) + ' หมวด)', amount: rest });
  const max = Math.max(1, ...top.map(c => c.amount));
  const total = categories.reduce((a, c) => a + c.amount, 0);
  return (
    <div className="viz stack" style={{ gap: 10, position: 'relative' }}>
      {top.map(c => (
        <div key={c.name} onMouseMove={e => show(e, <Fragment><TipRow color="var(--viz-actual)" label={c.name} value={fmt(c.amount)} />
          <TipRow label="สัดส่วน" value={(c.amount / total * 100).toFixed(1) + '%'} /></Fragment>)} onMouseLeave={hide}>
          <div className="row" style={{ justifyContent: 'space-between', fontSize: 12.5, marginBottom: 4 }}>
            <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{c.name}</span>
            <span className="num" style={{ fontWeight: 600 }}>{fmtCompact(c.amount)}</span>
          </div>
          <div className="hbar"><div style={{ width: (c.amount / max * 100) + '%' }} /></div>
        </div>
      ))}
      {tipView}
    </div>
  );
}

// ------------------------------------------------------------------ projects to follow up
function WatchList({ watch, total }) {
  if (!watch.length) {
    return <div className="card card-b row" style={{ gap: 10 }}><Icon name="ok" style={{ color: 'var(--green)' }} />
      <span>ไม่มีโครงการที่เบิกจ่ายช้ากว่าแผนหรือถูกระบุว่ามีความเสี่ยง</span></div>;
  }
  return (
    <div className="card">
      <div className="card-h">
        <div><h3>โครงการที่ต้องติดตาม</h3><div className="xs muted" style={{ marginTop: 3 }}>เรียงตามยอดที่ควรเบิกจ่ายแล้วแต่ยังไม่ได้จ่าย{total > watch.length ? ' · แสดง ' + watch.length + ' จาก ' + total + ' โครงการ' : ''}</div></div>
        <div className="legend">
          <span><span className="sw" style={{ width: 12, height: 8, background: 'var(--viz-actual)' }} />จ่ายจริง</span>
          <span><span style={{ width: 2, height: 12, background: 'var(--heading)', display: 'inline-block' }} />ควรถึงตามแผน</span>
        </div>
      </div>
      <div className="table-wrap">
        <table className="tbl" style={{ minWidth: 760 }}>
          <thead><tr><th>โครงการ</th><th>การติดตาม</th><th style={{ width: '28%' }}>ความก้าวหน้าการเบิกจ่าย</th><th className="num">อนุมัติ</th><th className="num">ค้างจ่ายตามแผน</th></tr></thead>
          <tbody>
            {watch.map(p => (
              <tr key={p.id} className="click" onClick={() => navigate('project', { id: p.id })}>
                <td>
                  <div className="xs mono muted">{p.code} · {p.unit_name}</div>
                  <div style={{ fontWeight: 500 }}>{p.title}</div>
                </td>
                <td>
                  <div className="stack" style={{ gap: 4, alignItems: 'flex-start' }}>
                    <span className="row" style={{ gap: 6, fontSize: 12.5 }}><span className="dot" style={{ background: TRACK[p.track][1] }} />{TRACK[p.track][0]}</span>
                    {p.health && <span className="xs muted"><HealthDot health={p.health} /></span>}
                    <span className="xs muted">{quarterText(p.quarters)}</span>
                  </div>
                </td>
                <td>
                  <div className="meter" style={{ marginTop: 6 }}>
                    <div style={{ width: Math.min(100, p.actual_pct) + '%' }} />
                    <span style={{ left: 'calc(' + Math.min(100, p.expected_pct) + '% - 1px)' }} />
                  </div>
                  <div className="xs muted" style={{ marginTop: 4 }}>จ่ายแล้ว {p.actual_pct}% · ควรถึง {p.expected_pct}%</div>
                </td>
                <td className="num">{fmt(p.allocated)}</td>
                <td className="num" style={{ fontWeight: 600, color: 'var(--red-text)' }}>{p.gap > 0 ? fmt(p.gap) : '–'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ table view (same numbers as the charts)
function OverviewTables({ d }) {
  return (
    <div className="grid-auto">
      <div className="card"><div className="card-h"><h3>การเบิกจ่ายสะสมรายเดือน</h3></div>
        <div className="table-wrap"><table className="tbl dense">
          <thead><tr><th>เดือน</th><th className="num">จ่ายในเดือน</th><th className="num">แผนสะสม</th><th className="num">จ่ายจริงสะสม</th><th className="num">% ของแผน</th></tr></thead>
          <tbody>{d.curve.map(c => (
            <tr key={c.month}><td>{FISCAL_MONTHS[c.month]}</td><td className="num">{fmt(c.spent)}</td><td className="num">{fmt(c.plan)}</td>
              <td className="num">{c.actual === null ? '–' : fmt(c.actual)}</td><td className="num">{c.actual !== null && c.plan ? (c.actual / c.plan * 100).toFixed(1) + '%' : '–'}</td></tr>
          ))}</tbody>
        </table></div>
      </div>
      <div className="card"><div className="card-h"><h3>ตามฝ่าย</h3></div>
        <div className="table-wrap"><table className="tbl dense">
          <thead><tr><th>ฝ่าย</th><th className="num">โครงการ</th><th className="num">อนุมัติ</th><th className="num">จ่ายจริง</th><th className="num">ควรถึงตามแผน</th></tr></thead>
          <tbody>{d.divisions.map(x => (
            <tr key={x.name}><td>{x.name}</td><td className="num">{x.projects}</td><td className="num">{fmt(x.allocated)}</td><td className="num">{fmt(x.spent)}</td><td className="num">{fmt(x.expected)}</td></tr>
          ))}</tbody>
        </table></div>
      </div>
      <div className="card"><div className="card-h"><h3>สถานะและขั้นตอน</h3></div>
        <div className="table-wrap"><table className="tbl dense">
          <tbody>
            {Object.keys(TRACK).map(k => <tr key={k}><td>การเบิกจ่าย: {TRACK[k][0]}</td><td className="num">{d.track[k]}</td></tr>)}
            {d.stages.map(s => <tr key={s.key}><td>ขั้นตอน: {s.label}</td><td className="num">{s.count}</td></tr>)}
          </tbody>
        </table></div>
      </div>
      <div className="card"><div className="card-h"><h3>ตามไตรมาส แหล่งเงิน และหมวดรายจ่าย</h3></div>
        <div className="table-wrap"><table className="tbl dense">
          <tbody>
            {d.quarters.map(q => <tr key={'q' + q.quarter}><td>{QUARTERS[q.quarter]} ({q.count} โครงการ)</td><td className="num">{fmt(q.planned)}</td></tr>)}
            {d.funds.map(f => <tr key={'f' + f.id}><td>แหล่งเงิน: {f.name}</td><td className="num">{fmt(f.allocated)}</td></tr>)}
            {d.categories.map(c => <tr key={'c' + c.name}><td>หมวด: {c.name}</td><td className="num">{fmt(c.amount)}</td></tr>)}
          </tbody>
        </table></div>
      </div>
    </div>
  );
}

Object.assign(window, { OverviewPage });
