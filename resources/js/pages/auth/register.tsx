import ClientAuthFlow from '@/components/client-auth-flow';

export default function Register() {
    return <ClientAuthFlow />;
}

Register.layout = {
    title: 'Create an account',
    description: 'Enter your name and email, confirm the OTP on screen, then set your password to access the website.',
};
