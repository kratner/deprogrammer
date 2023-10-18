interface ACastAudioProps {
  contentID: string;
  description: string;
  publishedDate?: string;
}

const ACastAudio: React.FC<ACastAudioProps> = ({ contentID, description }) => {
  return (
    <iframe
      src={`${contentID}`}
      frameBorder="0"
      width="100%"
      height="110px"
      allow="autoplay"
      title={`${description}`}
    ></iframe>
  );
};

export default ACastAudio;
