import { createContext } from 'react';

import type { UserRole } from '../types/admin';

export interface AuthUser {
  id: number;
  email: string;
  display_name: string;
  role: UserRole;
}

export interface AuthState {
  /** 読み込み中は user が確定していない。 */
  loading: boolean;
  user: AuthUser | null;
  reload: () => Promise<void>;
  logout: () => Promise<void>;
}

// Context をコンポーネントと同じファイルに置くと Fast Refresh が効かなくなるため分離している
export const AuthContext = createContext<AuthState | null>(null);
