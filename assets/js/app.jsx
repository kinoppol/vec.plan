// app.jsx — root: session, meta (per fiscal year), page routing

const PAGES = {
  dashboard: () => <Dashboard />,
  projects: () => <ProjectsPage />,
  project: () => <ProjectPage />,
  import: () => <ImportPage />,
  funds: () => <FundsPage />,
  estimates: () => <EstimatesPage />,
  receive: () => <ReceivePage />,
  'fund-entry': () => <FundEntryPage />,
  ledger: () => <LedgerPage />,
  settings: () => <SettingsPage />,
  institutions: () => <InstitutionsPage />,
  users: () => <UsersPage />,
  migrations: () => <MigrationsPage />,
  backups: () => <BackupsPage />,
  audit: () => <AuditPage />,
};

function App() {
  const route = useRoute();
  const [toast, toastView] = useToasts();
  const [meta, setMeta] = useState(null);
  const [metaError, setMetaError] = useState(null);
  const [fy, setFyState] = useState(() => { try { return +sessionStorage.getItem('vecplan_fy') || null; } catch (e) { return null; } });
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [version, setVersion] = useState(0);

  const loadMeta = useCallback(async (fyId) => {
    try {
      const m = await api('meta/index', { query: fyId ? { fy: fyId } : {} });
      apiState.fy = m.fy;
      setMeta(m);
      setMetaError(null);
    } catch (e) {
      // A stale fiscal year id in session storage: fall back to the default year.
      if (fyId && e.status === 404) { try { sessionStorage.removeItem('vecplan_fy'); } catch (x) { } return loadMeta(null); }
      setMetaError(e);
    }
  }, []);
  useEffect(() => {
    apiState.onUnauthorized = () => { window.location.reload(); };
    loadMeta(fy);
  }, []);

  const setFy = id => {
    try { sessionStorage.setItem('vecplan_fy', id); } catch (e) { /* storage blocked */ }
    setFyState(id);
    loadMeta(id).then(() => setVersion(v => v + 1));
  };
  const logout = async () => {
    try { await post('auth/logout'); } catch (e) { /* already logged out */ }
    try { sessionStorage.removeItem('vecplan_fy'); } catch (e) { }
    window.location.hash = '';
    window.location.reload();
  };

  if (metaError) return <div className="content" style={{ maxWidth: 720, margin: '40px auto' }}><LoadError error={metaError} onRetry={() => loadMeta(fy)} /></div>;
  if (!meta) return <div className="content"><Skeleton /></div>;

  const ctx = {
    meta, toast, setFy, navigate,
    reloadMeta: () => loadMeta(meta.fy),
    funds: buildFunds(meta.funds),
    units: buildUnits(meta.units),
    central: !meta.fy,
  };
  // The central admin has no fiscal year: only the admin pages exist for them.
  const central = !meta.fy;
  const page = central
    ? (CENTRAL_PAGES.includes(route.page) ? route.page : 'institutions')
    : (PAGES[route.page] ? route.page : 'dashboard');
  const navPage = page === 'project' ? 'projects' : page;
  return (
    <AppCtx.Provider value={ctx}>
      <div className={'app' + (collapsed ? ' collapsed' : '') + (mobileOpen ? ' mobile-open' : '')}>
        <Sidebar page={navPage} collapsed={collapsed} onNavigate={p => { setMobileOpen(false); navigate(p); }} />
        {mobileOpen && <div className="backdrop only-mobile" style={{ zIndex: 79 }} onClick={() => setMobileOpen(false)} />}
        <div className="main">
          <Topbar onToggle={() => window.innerWidth <= 860 ? setMobileOpen(o => !o) : setCollapsed(c => !c)} onLogout={logout} />
          <main className="content" key={route.page + '|' + version + '|' + JSON.stringify(route.params)}>
            {PAGES[page]()}
          </main>
        </div>
      </div>
      {toastView}
    </AppCtx.Provider>
  );
}

function Root() {
  if (!BOOT.logged_in) return <Login onDone={() => window.location.reload()} />;
  return <App />;
}

ReactDOM.createRoot(document.getElementById('root')).render(<Root />);
