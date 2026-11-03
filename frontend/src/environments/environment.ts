import type { DemoAccount } from '../app/core/services/auth.service';

export const environment = {
  production: true,
  apiUrl: 'http://127.0.0.1:8000/api/v1',
  appName: "Kermesse Saint Andrew's",
  demoAccounts: [] as DemoAccount[],
};
