import { title } from 'process';
import React from 'react';

interface IHeartRadioPlayerProps {
  episodeId: string;
  width?: string;
  height?: string;
  title?: string;
}

const IHeartRadioPlayer: React.FC<IHeartRadioPlayerProps> = ({ episodeId, width = '100%', height = '100%' }) => {
  const embedUrl = `https://www.iheart.com/podcast/${episodeId}`;

  return (
    <iframe
      src={embedUrl}
      title={title}
      width={width}
      height={height}
      frameBorder="0"
      scrolling="no"
      style={{ border: 'none' }}
      allowFullScreen
    ></iframe>
  );
};

export default IHeartRadioPlayer;
