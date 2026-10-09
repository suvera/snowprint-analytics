import { render, screen } from '@testing-library/react';
import { Snippet } from './Snippet';

test('uses the configured public tracking URL', () => {
  render(<Snippet domain="example.com" publicUrl="https://stats.example.com/" />);
  expect(screen.getByText('<script defer src="https://stats.example.com/snow.js" data-domain="example.com"></script>')).toBeTruthy();
});

test('falls back to the dashboard origin and says how to change it', () => {
  render(<Snippet domain="example.com" publicUrl="" />);
  expect(screen.getByText(`<script defer src="${window.location.origin}/snow.js" data-domain="example.com"></script>`)).toBeTruthy();
  expect(screen.getByText(/SNOWPRINT_PUBLIC_URL/)).toBeTruthy();
});
