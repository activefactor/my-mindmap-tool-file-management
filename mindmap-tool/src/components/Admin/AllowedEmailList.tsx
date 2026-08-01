import { AllowListPanel } from './AllowListPanel';

/** 許可アドレス管理（FR-08-4）。 */
export const AllowedEmailList = () => (
  <AllowListPanel
    endpoint="/api/admin/allowed-emails"
    bodyKey="email"
    responseKey="emails"
    label="許可アドレス"
    placeholder="someone@example.com"
    description={
      '個別に許可するメールアドレスです。許可ドメインに含まれないアカウント' +
      '（社外の協力者や個人の Gmail など）を利用者に加える場合に登録します。'
    }
  />
);
