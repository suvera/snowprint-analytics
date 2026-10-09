import { describe, expect, it } from 'vitest';
import { describeAccess } from './pages/UsersPage';
import { visibleSections } from './sections';

describe('access', () => {
  it('shows settings only to site managers', () => {
    expect(visibleSections(false).map((s) => s.id)).not.toContain('settings');
    expect(visibleSections(true).map((s) => s.id)).toContain('settings');
  });

  it('describes a user\'s access', () => {
    expect(describeAccess(true, {})).toBe('Admin of everything');
    expect(describeAccess(false, {})).toBe('No sites');
    expect(describeAccess(false, { 'a.com': 'viewer', 'b.com': 'admin' })).toBe('a.com (viewer), b.com (admin)');
  });
});
