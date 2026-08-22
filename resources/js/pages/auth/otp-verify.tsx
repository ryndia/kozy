import { Form, Head } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';

type Props = {
    email?: string;
    status?: string;
};

export default function OtpVerify({ email, status }: Props) {
    const [code, setCode] = useState('');

    return (
        <>
            <Head title="Verify your code" />

            <div className="space-y-6">
                <div className="space-y-2 text-center">
                    <p className="text-sm text-muted-foreground">
                        We sent a 6-digit code to your registered email.
                    </p>
                    {email && (
                        <p className="text-sm font-medium text-foreground">
                            {email}
                        </p>
                    )}
                </div>

                {status && (
                    <div className="text-center text-sm font-medium text-green-600">
                        {status}
                    </div>
                )}

                <Form method="post" action="/otp/verify" className="space-y-4">
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col items-center justify-center space-y-3 text-center">
                                <div className="flex w-full items-center justify-center">
                                    <InputOTP
                                        name="otp"
                                        maxLength={6}
                                        value={code}
                                        onChange={(value) => setCode(value)}
                                        disabled={processing}
                                        pattern={REGEXP_ONLY_DIGITS}
                                        autoFocus
                                    >
                                        <InputOTPGroup>
                                            {Array.from({ length: 6 }, (_, index) => (
                                                <InputOTPSlot
                                                    key={index}
                                                    index={index}
                                                />
                                            ))}
                                        </InputOTPGroup>
                                    </InputOTP>
                                </div>
                                <InputError message={errors.otp} />
                            </div>

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing ? 'Verifying...' : 'Verify code'}
                            </Button>
                        </>
                    )}
                </Form>

                <div className="text-center text-sm text-muted-foreground">
                    Need a new code? Please log in again to generate a fresh OTP.
                </div>
            </div>
        </>
    );
}

OtpVerify.layout = {
    title: 'Verify your renter access',
    description: 'Enter the 6-digit code to continue using the app.',
};
