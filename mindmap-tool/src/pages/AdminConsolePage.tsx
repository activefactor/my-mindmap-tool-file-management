import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { AllowedDomainList } from '../components/Admin/AllowedDomainList';
import { AllowedEmailList } from '../components/Admin/AllowedEmailList';
import { AuditLogTable } from '../components/Admin/AuditLogTable';
import { StorageUsagePanel } from '../components/Admin/StorageUsagePanel';
import { UserTable } from '../components/Admin/UserTable';
import { useAuth } from '../hooks/useAuth';

type TabId = 'users' | 'allow-list' | 'audit-logs' | 'storage';

const TABS: { id: TabId; label: string }[] = [
  { id: 'users', label: 'ユーザー' },
  { id: 'allow-list', label: '許可リスト' },
  { id: 'audit-logs', label: '監査ログ' },
  { id: 'storage', label: 'ストレージ' },
];

/** 管理コンソール（FR-08、基本設計書_Phase2.md §3.2, §4.5）。 */
export const AdminConsolePage = () => {
  const { user, logout } = useAuth();
  const [tab, setTab] = useState<TabId>('users');

  useEffect(() => {
    document.title = '管理コンソール | マインドマップツール';
  }, []);

  return (
    <div className="admin-page">
      <header className="admin-header">
        <h1 className="admin-title">管理コンソール</h1>
        <div className="admin-header-actions">
          <Link className="admin-button admin-button--ghost" to="/dashboard">
            ダッシュボードへ
          </Link>
          <span className="admin-user">{user?.email}</span>
          <button className="admin-button admin-button--ghost" type="button" onClick={() => void logout()}>
            ログアウト
          </button>
        </div>
      </header>

      <nav className="admin-tabs" role="tablist">
        {TABS.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={tab === item.id}
            className={tab === item.id ? 'admin-tab admin-tab--active' : 'admin-tab'}
            onClick={() => setTab(item.id)}
          >
            {item.label}
          </button>
        ))}
      </nav>

      <main className="admin-content">
        {tab === 'users' && <UserTable />}
        {tab === 'allow-list' && (
          <>
            <AllowedDomainList />
            <AllowedEmailList />
          </>
        )}
        {tab === 'audit-logs' && <AuditLogTable />}
        {tab === 'storage' && <StorageUsagePanel />}
      </main>
    </div>
  );
};
