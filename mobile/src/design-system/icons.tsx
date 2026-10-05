// Small inline stroke-icon set, matching the shapes used in the Artifact mockup
// (mobile_plan design reference). Kept as plain functions over react-native-svg
// rather than an icon font/library, mirroring the mockup's approach.
import Svg, { Circle, Path } from 'react-native-svg';

type IconProps = { size?: number; color?: string };

const base = { fill: 'none', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };

export function IconBack({ size = 22, color = '#fff' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Path d="M15 18l-6-6 6-6" />
    </Svg>
  );
}

export function IconHome({ size = 20, color = '#9aa0ac' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Path d="M3 11l9-8 9 8" />
      <Path d="M5 10v10h14V10" />
    </Svg>
  );
}

export function IconPackage({ size = 20, color = '#9aa0ac' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Path d="M21 8l-9-5-9 5 9 5 9-5z" />
      <Path d="M3 8v8l9 5 9-5V8" />
      <Path d="M12 13v8" />
    </Svg>
  );
}

export function IconTruck({ size = 20, color = '#9aa0ac' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Path d="M3 7h11v8H3z" />
      <Path d="M14 11h4l3 3v3h-7z" />
      <Circle cx="7" cy="18" r="1.5" />
      <Circle cx="17" cy="18" r="1.5" />
    </Svg>
  );
}

export function IconRupeeCircle({ size = 20, color = '#9aa0ac' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Circle cx="12" cy="12" r="9" />
      <Path d="M9 8h5a2 2 0 010 4H9m0-4v8m0-4h5" />
    </Svg>
  );
}

export function IconUser({ size = 20, color = '#9aa0ac' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Circle cx="12" cy="8" r="4" />
      <Path d="M4 20c0-4 4-6 8-6s8 2 8 6" />
    </Svg>
  );
}

export function IconBell({ size = 20, color = '#fff' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Path d="M6 8a6 6 0 1112 0c0 4 1.5 5 1.5 6.5H4.5C4.5 13 6 12 6 8z" />
      <Path d="M9.5 18a2.5 2.5 0 005 0" />
    </Svg>
  );
}

export function IconCheckCircle({ size = 20, color = '#2e7d32' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Circle cx="12" cy="12" r="9" />
      <Path d="M8 12l3 3 6-6" />
    </Svg>
  );
}

export function IconAlertTriangle({ size = 20, color = '#e65100' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Path d="M12 3l9 16H3l9-16z" />
      <Path d="M12 9v5" />
    </Svg>
  );
}

export function IconCamera({ size = 20, color = '#1a237e' }: IconProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" stroke={color} {...base}>
      <Path d="M4 8h3l2-2h6l2 2h3v11H4z" />
      <Circle cx="12" cy="13" r="3.5" />
    </Svg>
  );
}
