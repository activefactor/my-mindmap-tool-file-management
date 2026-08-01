/**
 * 管理コンソール（FR-08）で扱う型。
 * サーバー側の `App\Http\Controller\AdminController` のレスポンス形状に対応する。
 */

export type UserRole = 'user' | 'admin';
export type UserStatus = 'active' | 'disabled';

export interface AdminUser {
  id: number;
  email: string;
  display_name: string;
  role: UserRole;
  status: UserStatus;
  last_login_at: string | null;
  created_at: string;
}

export interface PaginationMeta {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export interface AdminUserListResponse {
  users: AdminUser[];
  pagination: PaginationMeta;
}

/** 許可ドメイン・許可アドレスは同じ形状で返る（値は `value`）。 */
export interface AllowListEntry {
  id: number;
  value: string;
  created_at: string;
  created_by_email: string | null;
}

export interface AuditLogEntry {
  id: number;
  action: string;
  actor_user_id: number | null;
  actor_email: string | null;
  target: string | null;
  /** JSONカラムのため構造は操作種別ごとに異なる。表示は整形済みJSONとして扱う。 */
  detail: Record<string, unknown> | null;
  created_at: string;
}

export interface AuditLogListResponse {
  logs: AuditLogEntry[];
  actions: string[];
  pagination: PaginationMeta;
}

export interface StorageUsagePerUser {
  user_id: number;
  email: string;
  display_name: string;
  map_count: number;
  active_map_count: number;
  trashed_map_count: number;
  approx_bytes: number;
}

export interface StorageUsageResponse {
  per_user: StorageUsagePerUser[];
  total: { map_count: number; approx_bytes: number };
  note: string;
}
