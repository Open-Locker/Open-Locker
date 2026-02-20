/// <reference types="jest" />
import * as React from 'react';
import { render } from '@testing-library/react-native';

import { MonoText } from '../StyledText';

describe('MonoText', () => {
  it('renders text content', () => {
    const { getByText } = render(<MonoText>Snapshot test!</MonoText>);

    expect(getByText('Snapshot test!')).toBeTruthy();
  });
});
