/** Who runs this installation, for the legal pages. Set at build time; the placeholders show when it was not. */
export const OPERATOR_NAME: string = import.meta.env.VITE_OPERATOR_NAME || '[operator name]'
export const OPERATOR_EMAIL: string = import.meta.env.VITE_OPERATOR_EMAIL || '[contact email]'
export const OPERATOR_SET: boolean = Boolean(import.meta.env.VITE_OPERATOR_NAME && import.meta.env.VITE_OPERATOR_EMAIL)
