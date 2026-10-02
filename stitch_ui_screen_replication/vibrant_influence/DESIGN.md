---
name: Vibrant Influence
colors:
  surface: '#fff8f7'
  surface-dim: '#f1d3d6'
  surface-bright: '#fff8f7'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#fff0f1'
  surface-container: '#ffe9ea'
  surface-container-high: '#ffe1e4'
  surface-container-highest: '#fadbde'
  on-surface: '#28171a'
  on-surface-variant: '#5b3f43'
  inverse-surface: '#3e2b2e'
  inverse-on-surface: '#ffecee'
  outline: '#8f6f73'
  outline-variant: '#e4bdc2'
  surface-tint: '#bc004b'
  primary: '#b80049'
  on-primary: '#ffffff'
  primary-container: '#e2165f'
  on-primary-container: '#fffbff'
  inverse-primary: '#ffb2be'
  secondary: '#632ce5'
  on-secondary: '#ffffff'
  secondary-container: '#7c4dff'
  on-secondary-container: '#fcf6ff'
  tertiary: '#006a34'
  on-tertiary: '#ffffff'
  tertiary-container: '#008644'
  on-tertiary-container: '#f6fff3'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#ffd9de'
  primary-fixed-dim: '#ffb2be'
  on-primary-fixed: '#400014'
  on-primary-fixed-variant: '#900038'
  secondary-fixed: '#e8deff'
  secondary-fixed-dim: '#cdbdff'
  on-secondary-fixed: '#20005f'
  on-secondary-fixed-variant: '#4f00d0'
  tertiary-fixed: '#89faa8'
  tertiary-fixed-dim: '#6ddd8e'
  on-tertiary-fixed: '#00210c'
  on-tertiary-fixed-variant: '#005227'
  background: '#fff8f7'
  on-background: '#28171a'
  surface-variant: '#fadbde'
typography:
  display-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 32px
  headline-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
  button:
    fontFamily: Plus Jakarta Sans
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 20px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 8px
  sm: 12px
  md: 16px
  lg: 24px
  xl: 32px
  gutter: 16px
  margin-mobile: 20px
  margin-desktop: 64px
---

## Brand & Style

This design system centers on a high-energy, "Vibrant Influence" aesthetic tailored for social commerce and creator marketplaces. The brand personality is optimistic, energetic, and professional, blending a youthful color palette with a clean, structured layout. 

The design style is a hybrid of **Corporate Modern** and **Soft Minimalism**. It utilizes a light-flooded UI with heavy whitespace to let creator content take center stage, while employing high-saturation primary accents to drive action. Visual depth is achieved through "2xl" rounded containers and subtle tonal layering rather than aggressive shadows, creating an interface that feels approachable, premium, and inherently mobile-first.

## Colors

The palette is anchored by a vibrant pink primary color used for key actions and brand identifiers. A secondary deep violet is utilized for interactive chat bubbles and supplementary branding elements to add depth. 

The foundation of the interface is a cool light gray background, which provides more sophisticated contrast for white cards than a pure white canvas. Status colors follow a standard semantic logic but are balanced in saturation to match the primary brand color's intensity. Text is rendered in a deep charcoal (Neutral Main) to ensure high readability without the harshness of pure black.

## Typography

This design system uses **Plus Jakarta Sans** across all levels to maintain a friendly, contemporary, and highly legible feel. The type scale is optimized for mobile-first consumption, emphasizing clear hierarchy through weight variation rather than extreme size differences.

Headlines use semi-bold and bold weights with tighter letter spacing for a punchy, editorial look. Body text is kept clean and open, while labels use a slightly heavier weight to remain legible at smaller sizes.

## Layout & Spacing

The layout follows a **fluid grid** model with a focus on internal card padding and consistent outer margins. On mobile devices, a 20px side margin is maintained to prevent content from feeling cramped against the screen edges.

The spacing rhythm is based on a 4px baseline grid. 16px (md) is the standard padding for cards and containers, while 24px (lg) is used to separate distinct content sections. Vertical rhythm should prioritize generous whitespace between headers and body content to ensure the UI feels "airy" and unhurried.

## Elevation & Depth

Hierarchy is established through **Tonal Layers** and extremely soft **Ambient Shadows**. 

1.  **Level 0 (Base):** Light gray background (#F8F9FA).
2.  **Level 1 (Cards):** Pure white surfaces with a subtle 1px border (#EEEEEE) or a very soft, high-blur shadow (0px 4px 20px rgba(0,0,0,0.04)).
3.  **Level 2 (Modals/Overlays):** White surfaces with a more pronounced shadow to indicate focus.

Interactive elements like buttons use a solid primary fill, creating depth through color saturation rather than traditional 3D effects.

## Shapes

The shape language is defined by **pronounced roundness**, echoing the friendly nature of the brand. Cards use a "2xl" radius (1.5rem) to create a soft, friendly "container" look that frames photography beautifully.

Buttons use a softer radius (0.75rem) to differentiate them from cards while remaining consistent with the overall organic aesthetic. Small UI elements like tags or chips utilize pill-shapes for maximum approachability.

## Components

### Buttons
Primary buttons are full-width on mobile, using the primary pink fill with white text. Secondary buttons use a light pink tint or a ghost style with a 1px border.

### Cards
Cards are the primary layout vehicle. They must always have a white background, "2xl" corner radius, and 16px internal padding. Content inside cards should be aligned to a clear vertical axis.

### Input Fields
Inputs use a light background or a thin gray border. Focus states must be clearly indicated with a primary pink border or a soft outer glow.

### Status Chips
Status indicators (Success, Pending, Error) use a low-opacity background tint of their respective semantic color with high-contrast text of the same hue. They should always have a fully rounded (pill) shape.

### Iconography
Icons should be **linear** with a 1.5px or 2px stroke weight and rounded terminals. They should be consistently sized (usually 24x24px) and use the Neutral Muted color unless they are active, in which case they take the Primary color.