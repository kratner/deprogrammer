import React, { useEffect } from 'react';

interface GoogleTagProps {
  trackingId: string;
}

declare global {
  interface Window {
    dataLayer: any[];
    gtag: (...args: any[]) => void;
  }
}

const GoogleTag: React.FC<GoogleTagProps> = ({ trackingId }) => {
  useEffect(() => {
    // Load Google Tag Manager script
    const script = document.createElement('script');
    script.src = `https://www.googletagmanager.com/gtag/js?id=${trackingId}`;
    script.async = true;
    document.body.appendChild(script);

    // Initialize Google Tag Manager
    window.dataLayer = window.dataLayer || [];
    window.gtag = function gtag() {
      window.dataLayer.push(arguments);
    };
    window.gtag('js', new window.Date());
    window.gtag('config', trackingId);

    // Clean up the script tag on unmount
    return () => {
      document.body.removeChild(script);
    };
  }, [trackingId]);

  return null;
};

export default GoogleTag;
