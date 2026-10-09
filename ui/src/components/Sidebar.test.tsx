import { render, screen } from '@testing-library/react';
import { Sidebar } from './Sidebar';

test('a share link shows read-only sections under its own route', () => {
  render(<Sidebar sites={[]} site="example.com" sectionId="pages" canManage={false}
    params={new URLSearchParams('period=30d')} share="abc123" />);

  expect(screen.getByRole('link', { name: 'Pages' }).getAttribute('href')).toBe('#/share/abc123/pages?period=30d');
  expect(screen.queryByRole('link', { name: 'Settings' })).toBeNull();
  expect(screen.queryByRole('combobox')).toBeNull();          // no site switcher
  expect(screen.queryByRole('button', { name: 'Sign out' })).toBeNull();
  expect(screen.getByText('Read-only view')).toBeTruthy();
});
