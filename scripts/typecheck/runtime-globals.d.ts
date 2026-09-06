interface FGConnectionCheck {
  status: string;
  label?: string;
  detail?: string;
}

interface FGConnectionResponse {
  success: boolean;
  data?: { checks?: FGConnectionCheck[] };
}

interface FGFinanceRow {
  field: string;
  value: string | number | null;
  currency?: string | null;
  status: string;
  currency_status?: string | null;
  source?: string | null;
  divisor: string | number | null;
}

interface FGFinancePreview {
  order_id: string | number;
  gateway_matches: boolean;
  order_gateway?: string;
  selected_gateway?: string;
  affiliate_adapter: boolean;
  rows: FGFinanceRow[];
  note: string;
}

interface FGFinanceResponse {
  success: boolean;
  data?: (FGFinancePreview & { message?: string }) | { message?: string };
}

interface FGValidationError {
  path: string;
  message: string;
}

interface FGBlockValidationResult {
  valid: boolean;
  blockCount: number;
  errors: FGValidationError[];
}

interface FGGutenbergBlock {
  name: string;
  isValid: boolean;
  originalContent?: string;
  innerBlocks?: FGGutenbergBlock[];
}

interface FGGutenbergBlocks {
  parse(markup: string): FGGutenbergBlock[];
  serialize(blocks: FGGutenbergBlock[]): string;
  createBlock(name: string, attributes: Record<string, unknown>, innerBlocks: FGGutenbergBlock[]): FGGutenbergBlock;
  validateBlock(block: FGGutenbergBlock): [boolean, ...unknown[]];
  getBlockType(name: string): unknown;
}

interface Window {
  fgConnection?: { ajaxUrl: string; nonce: string };
  fgFinance?: { ajaxUrl: string; nonce: string };
  JalinDesignValidation?: {
    validate(markup: string): FGBlockValidationResult;
  };
  wp?: {
    blocks: FGGutenbergBlocks;
    blockLibrary: { registerCoreBlocks(): void };
  };
}

declare const fgConnection: NonNullable<Window['fgConnection']>;
declare const wp: NonNullable<Window['wp']>;
