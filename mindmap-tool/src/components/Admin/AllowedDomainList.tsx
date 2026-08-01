import { AllowListPanel } from './AllowListPanel';

/** 許可ドメイン管理（FR-08-3）。 */
export const AllowedDomainList = () => (
  <AllowListPanel
    endpoint="/api/admin/allowed-domains"
    bodyKey="domain"
    responseKey="domains"
    label="許可ドメイン"
    placeholder="company.co.jp"
    description={
      'このドメインのメールアドレスを持つアカウントがログインできます。' +
      'Google の個人アカウント（Gmail など）は組織ドメインの情報を持たないため、' +
      'ドメイン指定では許可されません。個別に許可アドレスへ登録してください。'
    }
  />
);
