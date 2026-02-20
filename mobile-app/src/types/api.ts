export type TokenResponse = {
  token: string;
  name: string;
  verified?: boolean;
};

export type ItemDto = {
  id: number;
  name: string;
  description: string | null;
  image_url: string | null;
  compartment_id?: string;
  borrowed_at: string | null;
  created_at?: string;
  updated_at?: string;
};

export type LockerBankDto = {
  id: string;
  name: string;
  location_description: string;
};

export type CompartmentDto = {
  id: string;
  locker_bank_id: string;
  number: number;
  slave_id: number | null;
  address: number | null;
  locker_bank: LockerBankDto | null;
  item: ItemDto | null;
};
